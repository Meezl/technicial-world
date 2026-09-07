<?php

namespace App\Notifications;

use App\Models\JobAuthorisation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An advance authorisation is about to run out.
 *
 * Sent while there is still time to do something about it — renew it, chase
 * the deposit, or stand the crew down deliberately rather than discovering the
 * job is gated when a technician is already on site.
 */
class JobAuthorisationExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private JobAuthorisation $authorisation)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $authorisation = $this->authorisation;
        $job = $authorisation->serviceRequest;
        $hours = max(1, (int) round(now()->diffInHours($authorisation->expires_at, false)));

        $message = (new MailMessage)
            ->subject("Advance authorisation lapses in {$hours}h — {$job->request_id}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("The advance authorisation on **{$job->request_id}** lapses on "
                . $authorisation->expires_at->format('D d M Y, H:i') . ".")
            ->line('**Job:** ' . ($job->description ?? 'Service request'))
            ->line('**Client:** ' . ($job->user->name ?? 'Unknown'))
            ->line('**Authorisation:** ' . $authorisation->label())
            ->line('**Reason given:** ' . $authorisation->reason);

        if ($authorisation->exposure_cap) {
            $message->line('**Exposure cap:** KSH ' . number_format((float) $authorisation->exposure_cap, 2));
        }

        // What actually changes at the deadline depends on whether the crew is
        // already out. Saying which makes the mail actionable rather than a
        // reminder to worry.
        $message->line($job->started_at
            ? 'Work is already under way. When this lapses the job keeps running, but it will no longer be covered — settle the deposit or renew the authorisation.'
            : 'Work has not started. When this lapses the job is gated again and the technician will not be able to start.');

        return $message
            ->action('Open the job', url('/admin/jobs/' . $job->id))
            ->line('Renew or withdraw it from the job page.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_authorisation_expiring',
            'job_authorisation_id' => $this->authorisation->id,
            'service_request_id' => $this->authorisation->service_request_id,
            'request_id' => $this->authorisation->serviceRequest->request_id ?? null,
            'expires_at' => $this->authorisation->expires_at?->toDateTimeString(),
        ];
    }
}
