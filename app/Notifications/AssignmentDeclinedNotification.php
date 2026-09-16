<?php

namespace App\Notifications;

use App\Models\JobAssignment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A technician turned an assignment down — somebody needs to reassign it.
 */
class AssignmentDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private JobAssignment $assignment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $assignment = $this->assignment;
        $job = $assignment->serviceRequest;
        $name = $assignment->technician?->user?->name ?? 'A technician';

        $message = (new MailMessage)
            ->subject("{$name} declined {$job->request_id} — reassign needed")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("**{$name}** has declined their assignment on **{$job->request_id}**.")
            ->line('**Job:** ' . ($job->serviceCategory->name ?? 'Service request') . ' — ' . $job->location)
            ->line('**Client:** ' . ($job->user->name ?? 'Unknown'));

        if ($assignment->subTask) {
            $message->line('**Sub-task:** ' . $assignment->subTask->title);
        } elseif ($assignment->role_on_job) {
            $message->line('**Role:** ' . $assignment->role_on_job);
        }

        $url = $notifiable instanceof User && $notifiable->role === User::ROLE_PROJECT_MANAGER
            ? url('/pm/dashboard')
            : url('/admin/jobs/' . $job->id);

        return $message
            ->line('**Reason:** ' . $assignment->decline_reason)
            ->action('Reassign the job', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'assignment_declined',
            'job_assignment_id' => $this->assignment->id,
            'service_request_id' => $this->assignment->service_request_id,
            'request_id' => $this->assignment->serviceRequest->request_id ?? null,
            'technician_name' => $this->assignment->technician?->user?->name,
            'reason' => $this->assignment->decline_reason,
            'message' => ($this->assignment->technician?->user?->name ?? 'A technician')
                . ' declined ' . ($this->assignment->serviceRequest->request_id ?? 'a job'),
        ];
    }
}
