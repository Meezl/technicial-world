<?php

namespace App\Notifications;

use App\Models\JobAuthorisation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An advance authorisation has run out.
 *
 * The consequence differs by whether the crew is already out, and the two
 * cases need opposite responses — one is a job that just stopped, the other is
 * a job still running on money nobody is now covering. The second is the one
 * that goes unnoticed, so it is stated plainly.
 */
class JobAuthorisationLapsed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private JobAuthorisation $authorisation,
        private bool $runningUncovered
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $authorisation = $this->authorisation;
        $job = $authorisation->serviceRequest;

        $message = (new MailMessage)
            ->subject(($this->runningUncovered ? 'Job now running uncovered' : 'Job gated again')
                . " — {$job->request_id}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("The advance authorisation on **{$job->request_id}** lapsed on "
                . $authorisation->expires_at->format('D d M Y, H:i') . '.')
            ->line('**Job:** ' . ($job->description ?? 'Service request'))
            ->line('**Client:** ' . ($job->user->name ?? 'Unknown'))
            ->line('**Reason it was granted:** ' . $authorisation->reason);

        if ($this->runningUncovered) {
            $message
                ->line('**Work is under way and no longer covered.** The crew has not been stopped — '
                    . 'a lapse mid-job does not pull people off site — but nothing is now standing '
                    . 'behind the labour and materials being spent.')
                ->line('Settle the deposit, renew the authorisation, or suspend the job.');
        } else {
            $message
                ->line('**The job is gated again.** The technician can no longer start it, and will see '
                    . 'that when they open the job.')
                ->line('Settle the deposit or renew the authorisation to release it.');
        }

        return $message->action('Open the job', url('/admin/jobs/' . $job->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_authorisation_lapsed',
            'job_authorisation_id' => $this->authorisation->id,
            'service_request_id' => $this->authorisation->service_request_id,
            'request_id' => $this->authorisation->serviceRequest->request_id ?? null,
            'running_uncovered' => $this->runningUncovered,
        ];
    }
}
