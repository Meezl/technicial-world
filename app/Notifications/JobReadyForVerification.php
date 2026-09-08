<?php

namespace App\Notifications;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The work is done and the office has accepted it — over to the client.
 *
 * Carries a link straight to the job. The verification also sits in their
 * portal, but a client who is not in the habit of logging in will only ever
 * see this, and a job nobody looks at is one the office ends up closing on
 * their behalf.
 *
 * Not queued: this is the message that starts a three-day clock, and a queue
 * failure would leave the office waiting on a client who was never asked.
 */
class JobReadyForVerification extends Notification
{
    use Queueable;

    public function __construct(private ServiceRequest $serviceRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $job = $this->serviceRequest;
        $days = ServiceRequest::CLIENT_VERIFICATION_DAYS;

        $message = (new MailMessage)
            ->subject("Please confirm the work on {$job->request_id}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("The work on **{$job->request_id}** is finished and has been checked by our office.")
            ->line('**Job:** ' . $job->description)
            ->line('**Location:** ' . $job->location);

        if ($job->completion_notes) {
            $message->line('**Our notes:** ' . $job->completion_notes);
        }

        return $message
            ->line("Please take a look and confirm you are happy with it. You can also rate the work, and tell us if anything is not right.")
            ->action('Review the work', url('/client/request-status/' . $job->id))
            ->line("If we do not hear from you within {$days} days we will close the job, though you can always come back to us.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_ready_for_verification',
            'service_request_id' => $this->serviceRequest->id,
            'request_id' => $this->serviceRequest->request_id,
        ];
    }
}
