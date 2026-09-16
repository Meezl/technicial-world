<?php

namespace App\Notifications;

use App\Models\JobAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A technician has been assigned work and has neither accepted nor declined.
 */
class AssignmentResponseReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private JobAssignment $assignment,
        private \Carbon\CarbonInterface $waitingSince,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $job = $this->assignment->serviceRequest;
        $waiting = $this->waitingSince->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2);

        $message = (new MailMessage)
            ->subject("Reminder: accept or decline your assignment on {$job->request_id}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("You were assigned to **{$job->request_id}** {$waiting} ago and have not yet accepted or declined it.")
            ->line('**Job:** ' . ($job->serviceCategory->name ?? 'Service request') . ' — ' . $job->location);

        if ($this->assignment->subTask) {
            $message->line('**Your task:** ' . $this->assignment->subTask->title);
        } elseif ($this->assignment->role_on_job) {
            $message->line('**Your role:** ' . $this->assignment->role_on_job);
        }

        if ($job->commencement_at) {
            $message->line('**Starts:** ' . $job->commencement_at->format('D d M Y, H:i'));
        }

        return $message
            ->action('Accept or decline', url('/technician/jobs/' . $job->id))
            ->line('If you cannot take this job, decline it so the office can reassign it quickly.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'assignment_response_reminder',
            'job_assignment_id' => $this->assignment->id,
            'service_request_id' => $this->assignment->service_request_id,
            'request_id' => $this->assignment->serviceRequest->request_id ?? null,
            'message' => 'Accept or decline your assignment on '
                . ($this->assignment->serviceRequest->request_id ?? 'a job'),
        ];
    }
}
