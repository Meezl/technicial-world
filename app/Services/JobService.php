<?php

namespace App\Services;

use App\Models\ServiceRequest;
use App\Models\JobStateLog;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class JobService
{
    /**
     * Transition a service request to a new status with validation and logging.
     */
    public function transitionState(
        ServiceRequest $serviceRequest,
        string $newStatus,
        ?string $reason = null,
        ?array $metadata = null
    ): ServiceRequest {
        $oldStatus = $serviceRequest->status;

        // Log the state transition
        JobStateLog::create([
            'service_request_id' => $serviceRequest->id,
            'from_state' => $oldStatus,
            'to_state' => $newStatus,
            'reason' => $reason,
            'triggered_by' => auth()->id(),
            'metadata' => $metadata,
        ]);

        // Audit log
        AuditLog::log(
            AuditLog::ACTION_STATE_CHANGED,
            $serviceRequest,
            ['status' => $oldStatus],
            ['status' => $newStatus]
        );

        $serviceRequest->update(['status' => $newStatus]);

        $serviceRequest = $serviceRequest->fresh();

        // A closed corporate job bills itself and spends the float.
        //
        // Hooked here rather than at the two closure call sites because both
        // of them — the client verifying, and the office closing without
        // them — pass through this method, and a job that closed one way and
        // not the other would silently never be invoiced.
        //
        // Raising is idempotent, so a job reopened and closed again does not
        // bill twice. Deliberately not allowed to take the transition down
        // with it: a failure to raise the invoice is worth an alert, but it
        // is not worth leaving the job stuck in a state it has already left.
        if ($newStatus === ServiceRequest::STATUS_CLOSED && $serviceRequest->isCorporate()) {
            try {
                app(\App\Services\InvoicingService::class)->raiseHeldInvoice($serviceRequest, auth()->user());
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Corporate invoice could not be raised on closure', [
                    'service_request_id' => $serviceRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $serviceRequest->fresh();
    }

    /**
     * Suspend a job with reason tracking.
     */
    public function suspend(ServiceRequest $serviceRequest, string $reason): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $reason) {
            $serviceRequest->update([
                'suspension_reason' => $reason,
                'suspended_at' => now(),
            ]);

            return $this->transitionState($serviceRequest, ServiceRequest::STATUS_SUSPENDED, $reason);
        });
    }

    /**
     * Resume a suspended job.
     */
    public function resume(ServiceRequest $serviceRequest, ?string $notes = null): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $notes) {
            $serviceRequest->update([
                'resumed_at' => now(),
            ]);

            return $this->transitionState($serviceRequest, ServiceRequest::STATUS_IN_PROGRESS, $notes);
        });
    }

    /**
     * Reassign a job to a different technician.
     */
    public function reassign(
        ServiceRequest $serviceRequest,
        int $newTechnicianId,
        string $reason
    ): ServiceRequest {
        return DB::transaction(function () use ($serviceRequest, $newTechnicianId, $reason) {
            $oldTechnicianId = $serviceRequest->technician_id;

            // Log the reassignment
            AuditLog::log(
                AuditLog::ACTION_REASSIGNMENT,
                $serviceRequest,
                ['technician_id' => $oldTechnicianId],
                ['technician_id' => $newTechnicianId, 'reason' => $reason]
            );

            $serviceRequest->update([
                'technician_id' => $newTechnicianId,
            ]);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_ASSIGNED,
                "Reassigned from technician #{$oldTechnicianId}: {$reason}",
                ['old_technician_id' => $oldTechnicianId, 'new_technician_id' => $newTechnicianId]
            );
        });
    }

    /**
     * The work is finished as far as site is concerned — the lead's call.
     *
     * This is the middle of three stages, not the end. A technician files
     * their hundred per cent, the lead signs it off, and the job lands here:
     * done on site, waiting on the office. It is deliberately not a terminal
     * status, so the job stays in the working list until somebody in the
     * office has actually looked at it.
     *
     * `completed_date` is not stamped here. A job that is sent back for
     * rework would otherwise carry a completion date through the fortnight it
     * spends being finished, and every report keyed off that date would be
     * wrong for as long as it took.
     */
    public function markCompleted(ServiceRequest $serviceRequest, ?string $reason = null): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $reason) {
            $serviceRequest->update(['progress_percentage' => 100]);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
                $reason ?? 'Lead technician signed the work off as complete on site.'
            );
        });
    }

    /**
     * The office accepts the work — and hands it to the client to verify.
     *
     * This is where the work is deemed delivered: the completion date is
     * stamped and the crew's job counts move, because a job sent back for
     * rework must never have been counted, and by this point it will not be.
     * It is not, however, the end: the client has the last word, and the job
     * only reaches a terminal status once they have had it.
     */
    public function approveCompletion(ServiceRequest $serviceRequest, User $approver, ?string $notes = null): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $approver, $notes) {
            // Read before the update: completed_date is what says the crew has
            // already been credited, and after saving it always looks set.
            $alreadyCredited = $serviceRequest->completed_date !== null;

            $serviceRequest->update([
                'progress_percentage' => 100,
                'completed_date' => now(),
                'completion_notes' => $notes ?: $serviceRequest->completion_notes,
                'client_verification_sent_at' => now(),
            ]);

            if (!$alreadyCredited) {
                $this->creditTechnicians($serviceRequest);
            }

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION,
                'Completion approved by ' . $approver->name . '; sent to the client to verify',
                ['approved_by' => $approver->id, 'notes' => $notes]
            );
        });
    }

    /**
     * Hand the approved job to the client to verify.
     *
     * The office's approval is no longer the end of the line: it is the point
     * at which the work is fit to show. The clock on
     * client_verification_sent_at is what lets the office close a job for a
     * client who never answers.
     */
    public function sendForClientVerification(ServiceRequest $serviceRequest, User $sentBy): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $sentBy) {
            $serviceRequest->update([
                'client_verification_sent_at' => now(),
                // A previous concern has been dealt with by the act of sending
                // it back; leaving it would show as still outstanding.
                'client_concern' => null,
                'client_concern_raised_at' => null,
            ]);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION,
                'Sent to the client for verification by ' . $sentBy->name
            );
        });
    }

    /**
     * The client is satisfied. This is the end of the line.
     *
     * The rating is optional and one score covers everyone who worked the job:
     * a client is rating the work they received, not conducting an appraisal
     * of each person on site.
     */
    public function clientVerifyAndClose(
        ServiceRequest $serviceRequest,
        ?int $rating = null,
        ?string $comment = null
    ): ServiceRequest {
        return DB::transaction(function () use ($serviceRequest, $rating, $comment) {
            $serviceRequest->update([
                'client_confirmed_completion' => true,
                'client_confirmation_date' => now(),
                'closed_without_client' => false,
                'closed_by' => $serviceRequest->user_id,
            ]);

            if ($rating !== null) {
                $this->recordRating($serviceRequest, $rating, $comment);
            } elseif ($comment) {
                // Kept even without a score — a client who writes something
                // has said something worth keeping.
                $serviceRequest->update(['review' => $comment]);
            }

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_CLOSED,
                'Verified and closed by the client'
            );
        });
    }

    /**
     * The client is not satisfied. Back to the office, not straight to site.
     *
     * The office triages it: a concern is as often a misunderstanding they can
     * answer as it is rework, and sending a crew back on the strength of one
     * sentence would waste a day finding that out.
     */
    public function clientRaiseConcern(ServiceRequest $serviceRequest, string $concern): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $concern) {
            $serviceRequest->update([
                'client_concern' => $concern,
                'client_concern_raised_at' => now(),
            ]);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_CLIENT_QUERY_RAISED,
                $concern
            );
        });
    }

    /**
     * Close a job the client never came back on.
     *
     * Flagged as closed without them. A job shut on a client's silence is not
     * a job they verified, and a report that counts the two together is
     * telling somebody the wrong thing about their own business.
     */
    public function closeWithoutClient(ServiceRequest $serviceRequest, User $closedBy, string $reason): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $closedBy, $reason) {
            $serviceRequest->update([
                'closed_by' => $closedBy->id,
                'closed_without_client' => true,
            ]);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_CLOSED,
                'Closed without client verification: ' . $reason
            );
        });
    }

    /**
     * One score, everyone who worked the job.
     *
     * A row per technician rather than a single field, because the flat
     * `service_requests.rating` only ever fed the primary technician's
     * average — on a job split into sub-tasks the crew were never rated at
     * all, however well they did.
     */
    private function recordRating(ServiceRequest $serviceRequest, int $rating, ?string $comment): void
    {
        $serviceRequest->update([
            'rating' => $rating,
            'review' => $comment,
        ]);

        $technicianIds = collect([$serviceRequest->technician_id, $serviceRequest->lead_technician_id])
            ->merge($serviceRequest->subTasks()->whereNotNull('technician_id')->pluck('technician_id'))
            ->merge(
                $serviceRequest->jobAssignments()
                    ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
                    ->pluck('technician_id')
            )
            ->filter()
            ->unique();

        foreach ($technicianIds as $id) {
            \App\Models\Review::updateOrCreate(
                ['service_request_id' => $serviceRequest->id, 'technician_id' => $id],
                ['user_id' => $serviceRequest->user_id, 'rating' => $rating, 'comment' => $comment]
            );

            $technician = \App\Models\Technician::find($id);
            if (!$technician) {
                continue;
            }

            // Averaged from the reviews themselves, so a technician's standing
            // reflects every job they actually worked rather than only the
            // ones where they happened to be the named lead.
            $average = \App\Models\Review::where('technician_id', $id)->avg('rating');
            $technician->update(['rating' => round((float) $average, 1)]);
        }
    }

    /**
     * Send it back. The office is not satisfied and the crew returns to site.
     *
     * A reason is required because this reaches a technician who believed
     * they had finished, and "rejected" on its own tells them nothing about
     * what to go back for.
     */
    public function returnForRework(ServiceRequest $serviceRequest, User $returnedBy, string $reason): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $returnedBy, $reason) {
            $serviceRequest->update([
                // Never delivered, so it carries no completion date and the
                // headline figure stops claiming the work is finished.
                'completed_date' => null,
                'progress_percentage' => min((int) $serviceRequest->progress_percentage, 99),
            ]);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_IN_PROGRESS,
                $reason,
                ['returned_by' => $returnedBy->id]
            );
        });
    }

    /**
     * Count the job against everyone who worked it, once.
     *
     * Guarded on the status rather than trusted to be called once: approving
     * a completion twice must not double a technician's record.
     */
    private function creditTechnicians(ServiceRequest $serviceRequest): void
    {
        $technicianIds = collect([$serviceRequest->technician_id, $serviceRequest->lead_technician_id])
            ->merge($serviceRequest->subTasks()->whereNotNull('technician_id')->pluck('technician_id'))
            ->merge(
                $serviceRequest->jobAssignments()
                    ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
                    ->pluck('technician_id')
            )
            ->filter()
            ->unique();

        foreach ($technicianIds as $id) {
            $technician = \App\Models\Technician::find($id);
            if (!$technician) {
                continue;
            }

            $technician->increment('total_jobs');

            // Free them up, unless they are still carrying other live work.
            $stillBusy = ServiceRequest::forTechnician($id)
                ->whereNotIn('status', array_merge(
                    ServiceRequest::TERMINAL_STATUSES,
                    [ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION]
                ))
                ->where('id', '!=', $serviceRequest->id)
                ->exists();

            if (!$stillBusy) {
                $technician->update(['availability' => 'available']);
            }
        }
    }

    /**
     * The client's own word that the job is done.
     *
     * Recorded, not decisive. The office signs a job off; a client saying so
     * is useful evidence and a useful nudge, but a request closed on it alone
     * would skip the review this whole pipeline exists to guarantee.
     */
    public function clientConfirmCompletion(ServiceRequest $serviceRequest): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest) {
            $serviceRequest->update([
                'client_confirmed_completion' => true,
                'client_confirmation_date' => now(),
            ]);

            return $serviceRequest->fresh();
        });
    }

    /**
     * Archive a closed job.
     */
    public function archive(ServiceRequest $serviceRequest): ServiceRequest
    {
        return $this->transitionState(
            $serviceRequest,
            ServiceRequest::STATUS_ARCHIVED,
            'Job archived'
        );
    }
}
