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
     * Mark job as completed pending confirmation.
     */
    public function markCompleted(ServiceRequest $serviceRequest): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest) {
            $serviceRequest->update([
                'progress_percentage' => 100,
                'completed_date' => now(),
            ]);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION
            );
        });
    }

    /**
     * Client confirms job completion.
     */
    public function clientConfirmCompletion(ServiceRequest $serviceRequest): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest) {
            $serviceRequest->update([
                'client_confirmed_completion' => true,
                'client_confirmation_date' => now(),
            ]);

            return $this->transitionState(
                $serviceRequest,
                ServiceRequest::STATUS_CLOSED,
                'Client confirmed job completion'
            );
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
