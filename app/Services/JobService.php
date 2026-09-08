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
     * The office has the last word: this is where an RFQ is deemed complete.
     *
     * Only here does the job become terminal, the completion date get stamped
     * and the crew's job counts move. Doing any of that at the lead's sign-off
     * would mean a job sent back for rework had already been counted as
     * delivered.
     */
    public function approveCompletion(ServiceRequest $serviceRequest, User $approver, ?string $notes = null): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $approver, $notes) {
            $serviceRequest->update([
                'progress_percentage' => 100,
                'completed_date' => now(),
                'completion_notes' => $notes ?: $serviceRequest->completion_notes,
            ]);

            $this->creditTechnicians($serviceRequest);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_CLOSED,
                'Completion approved by ' . $approver->name,
                ['approved_by' => $approver->id, 'notes' => $notes]
            );
        });
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
        if ($serviceRequest->status === ServiceRequest::STATUS_CLOSED) {
            return;
        }

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
