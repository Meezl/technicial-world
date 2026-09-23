<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JobAssignment;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Notifications\AssignmentDeclinedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A technician answering an assignment.
 *
 * Accepting records that they will do it. Declining takes them off the job
 * and tells the office, which is the point: a job nobody is coming to should
 * surface the same day, not when the client rings to ask where the crew is.
 */
class AssignmentResponseService
{
    public function accept(JobAssignment $assignment): JobAssignment
    {
        if (!$assignment->isAwaitingResponse()) {
            return $assignment;
        }

        $assignment->update([
            'status' => JobAssignment::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        AuditLog::log(AuditLog::ACTION_ASSIGNMENT, $assignment,
            ['status' => JobAssignment::STATUS_PENDING],
            ['status' => JobAssignment::STATUS_ACCEPTED, 'technician_id' => $assignment->technician_id]
        );

        return $assignment;
    }

    /**
     * Starting work is as clear an acceptance as the button. Without this a
     * technician who went straight to site would keep being reminded to
     * accept a job they are standing in.
     */
    public function acceptAllFor(ServiceRequest $serviceRequest, Technician $technician): void
    {
        $serviceRequest->jobAssignments()
            ->where('technician_id', $technician->id)
            ->where('status', JobAssignment::STATUS_PENDING)
            ->get()
            ->each(fn (JobAssignment $assignment) => $this->accept($assignment));
    }

    public function decline(JobAssignment $assignment, string $reason): JobAssignment
    {
        if (!$assignment->isAwaitingResponse()) {
            throw new \RuntimeException('Only an assignment that has not been answered can be declined.');
        }

        DB::transaction(function () use ($assignment, $reason) {
            $assignment->update([
                'status' => JobAssignment::STATUS_DECLINED,
                'decline_reason' => $reason,
                'responded_at' => now(),
                'actual_end' => now(),
            ]);

            $technicianId = (int) $assignment->technician_id;
            $job = $assignment->serviceRequest;

            // Take them off whatever slot the assignment put them in, so the
            // job stops appearing on their list and the office sees the gap.
            if ($assignment->service_sub_task_id) {
                $subTask = $assignment->subTask;
                if ($subTask && (int) $subTask->technician_id === $technicianId) {
                    $subTask->update(['technician_id' => null]);
                }
            } elseif ($job) {
                $updates = [];
                if ((int) $job->technician_id === $technicianId) {
                    $updates['technician_id'] = null;
                }
                if ((int) $job->lead_technician_id === $technicianId) {
                    $updates['lead_technician_id'] = null;
                }
                // Back to the office's queue — but only if work had not begun.
                // A job under way stays under way; the office decides who
                // carries it on.
                if ($updates && $job->status === ServiceRequest::STATUS_ASSIGNED && !$job->started_at) {
                    $updates['status'] = ServiceRequest::STATUS_READY_FOR_ASSIGNMENT;
                }
                if ($updates) {
                    $job->update($updates);
                }
            }

            AuditLog::log(AuditLog::ACTION_ASSIGNMENT, $assignment,
                ['status' => JobAssignment::STATUS_PENDING],
                ['status' => JobAssignment::STATUS_DECLINED, 'technician_id' => $technicianId, 'reason' => $reason]
            );
        });

        $this->tellOfficeAboutDecline($assignment->fresh(['serviceRequest.user', 'technician.user', 'subTask']));

        return $assignment;
    }

    private function tellOfficeAboutDecline(JobAssignment $assignment): void
    {
        $job = $assignment->serviceRequest;

        $recipients = (new Collection([
            $assignment->assigned_by ? User::find($assignment->assigned_by) : null,
            $job?->assigned_pm_id ? User::find($job->assigned_pm_id) : null,
        ]))
            ->merge(User::where('role', User::ROLE_ADMIN)->where('is_active', true)->get())
            ->filter()
            ->unique('id');

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new AssignmentDeclinedNotification($assignment));
            } catch (\Throwable $e) {
                Log::warning('Assignment decline notification failed', [
                    'job_assignment_id' => $assignment->id,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
