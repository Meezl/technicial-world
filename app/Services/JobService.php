<?php

namespace App\Services;

use App\Models\ServiceRequest;
use App\Models\VariationOrder;
use App\Models\JobStateLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ProgressService;
use Illuminate\Support\Facades\DB;

class JobService
{
    /**
     * Where a reopened job may land.
     *
     * Working states only. Reopening into a stage that is itself an ending —
     * or into the approval stages the job has long since passed — would be a
     * different operation wearing this one's name.
     */
    public const REOPENABLE_TO = [
        ServiceRequest::STATUS_IN_PROGRESS,
        ServiceRequest::STATUS_ASSIGNED,
        ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
        ServiceRequest::STATUS_SUSPENDED,
    ];

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
     * Put a finished job back to work because a variation bought more of it.
     *
     * The office may raise a variation on a job that has already been
     * delivered — the case that built the variation ledger was work done after
     * closure that the client still owed for. Where that variation only moves
     * money, the job stays closed and nothing here fires. Where it buys actual
     * work, somebody has to be able to do it, and a job reading "Closed" with
     * live tasks on it is a job nobody will look at.
     *
     * Deliberate and logged, through transitionState, so it appears in the
     * job's state history with the variation that caused it. That matters
     * because an undeclared reopen already existed: a recompute would flip a
     * completed job back to in_progress on its own. See
     * ProgressService::updateServiceRequestProgress.
     *
     * The client needs no separate notice. A priced variation is approved by
     * the client themselves in their portal, so they already know; a
     * zero-income one never reaches them by design.
     *
     * Cancelled and archived jobs are left alone. Cancelled means the work
     * never happened, and reviving it through a variation would be the wrong
     * instrument — that is a new request.
     */
    public function reopenForVariationWork(
        ServiceRequest $serviceRequest,
        VariationOrder $variationOrder,
        ?User $actor = null
    ): ?ServiceRequest {
        $reopenable = [
            ServiceRequest::STATUS_COMPLETED,
            ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
            ServiceRequest::STATUS_CLOSED,
        ];

        if (!in_array($serviceRequest->status, $reopenable, true)) {
            return null;
        }

        // Only live work reopens a job. A task still waiting on an admin, or
        // on the client agreeing the variation, is not work anybody can start.
        $live = $variationOrder->subTasks()
            ->whereNotNull('approved_at')
            ->whereNull('declined_at')
            ->where('progress_percentage', '<', 100)
            ->exists();

        if (!$live || !$variationOrder->isApproved()) {
            return null;
        }

        return $this->transitionState(
            $serviceRequest,
            ServiceRequest::STATUS_IN_PROGRESS,
            "Reopened for work bought by {$variationOrder->vo_number}.",
            [
                'reopened_for_variation' => $variationOrder->vo_number,
                'variation_order_id' => $variationOrder->id,
                'previous_status' => $serviceRequest->status,
                'reopened_by' => $actor?->id,
            ]
        );
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

            // Anything settled but never sent on goes now.
            //
            // The client is about to be asked whether the work is right, and
            // they cannot answer that against reports they have not been shown.
            // Releasing is the office's deliberate act while a job runs — which
            // is what stopped the fragmented one-email-per-report telling — but
            // a job reaching its end with a batch still held is the one case
            // where waiting for that act serves nobody. It is the last moment
            // the reports can still be of use.
            $progress = app(ProgressService::class);

            // Approving a job's completion is this person saying the work is
            // right, under their own name. That is the same judgement the
            // sign-off asks for on a report no lead reviewed, so it is recorded
            // as theirs rather than demanded again a second time — and a
            // release that refused here would block the job from completing at
            // all over paperwork the approver has just done.
            foreach ($progress->awaitingOpsVerification($serviceRequest) as $report) {
                $progress->verifyForRelease(
                    $report,
                    $approver,
                    'Signed off with the approval of this job\'s completion.'
                );
            }

            $progress->releaseToClient($serviceRequest, null, $approver->id);

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
     * Put a finished job back to work.
     *
     * The archive has had a reopen since it shipped, but only for cancelled
     * requests and only back to the status they were cancelled from, with a
     * comment saying completed and closed jobs were deliberately out of scope
     * because undoing a delivered job touches billing, technician payments and
     * the client's own record.
     *
     * That reasoning was sound and the conclusion was wrong. Jobs do get
     * closed in error — three of them reached a completed status with a trade
     * sitting at 0%, because the completion guard read a sub-task report's
     * percentage as the job's — and an office with no way back had to choose
     * between leaving a wrong record standing and editing the database. So the
     * answer is not to refuse it but to make it deliberate: admin only, a
     * reason that goes in the audit log, and nothing silently undone.
     *
     * What it does NOT do, and must not:
     *
     *  · it does not reverse a payment, a client invoice or a technician
     *    payout. Money that has moved has moved, and unwinding it is a
     *    separate decision with its own paperwork. A reopened job that gets
     *    closed again does not bill twice — milestones never re-trigger.
     *  · it does not revive progress reports that were removed, or un-release
     *    anything the client has already been sent. They saw what they saw.
     *  · it does not restore sub-task states. The work either happened or it
     *    did not, and the reports say which.
     *
     * What it does undo is the record of completion itself: the stamps that
     * say the work was delivered, verified and closed, which are untrue the
     * moment the job is open again. The headline percentage is recomputed
     * from the reports rather than left at the flat 100 markCompleted writes,
     * because a reopened job showing 100% is the same lie in a new place.
     */
    public function reopen(
        ServiceRequest $serviceRequest,
        User $reopenedBy,
        string $reason,
        string $toStatus = ServiceRequest::STATUS_IN_PROGRESS
    ): ServiceRequest {
        if (trim($reason) === '') {
            throw new \RuntimeException('Reopening a job needs a reason.');
        }

        if (!in_array($toStatus, self::REOPENABLE_TO, true)) {
            throw new \RuntimeException('A job cannot be reopened into "' . $toStatus . '".');
        }

        if ($serviceRequest->status === $toStatus) {
            throw new \RuntimeException('This job is already at "' . $toStatus . '".');
        }

        return DB::transaction(function () use ($serviceRequest, $reopenedBy, $reason, $toStatus) {
            $before = $serviceRequest->only([
                'status',
                'progress_percentage',
                'completed_date',
                'client_confirmed_completion',
                'client_confirmation_date',
                'closed_by',
                'closed_without_client',
            ]);

            // The completion record, cleared. Each of these asserts something
            // about a finished job and would otherwise go on asserting it
            // while the crew is back on site — the client verification window
            // in particular, which a sweep would act on.
            $serviceRequest->update([
                'completed_date' => null,
                'client_confirmed_completion' => false,
                'client_confirmation_date' => null,
                'client_verification_sent_at' => null,
                'closed_by' => null,
                'closed_without_client' => false,
                'lead_completion_note' => null,
                // A concern the client raised against the old closure is
                // answered by the reopening itself.
                'client_concern' => null,
                'client_concern_raised_at' => null,
            ]);

            $reopened = $this->transitionState(
                $serviceRequest,
                $toStatus,
                'Reopened by ' . $reopenedBy->name . ': ' . $reason,
                ['reopened_by' => $reopenedBy->id, 'from_status' => $before['status']]
            );

            // Status first, figure second. refreshHeadlinePercent writes only
            // the percentage — recalculate() would move the status too and
            // walk a job with a validated 100% whole-job report straight back
            // to where it came from.
            app(ProgressService::class)->refreshHeadlinePercent($reopened);

            AuditLog::log(
                AuditLog::ACTION_STATE_CHANGED,
                $reopened,
                $before,
                [
                    'event' => 'job_reopened',
                    'status' => $toStatus,
                    'reopened_by' => $reopenedBy->id,
                    'reason' => $reason,
                    'progress_percentage' => $reopened->fresh()->progress_percentage,
                ]
            );

            return $reopened->fresh();
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
