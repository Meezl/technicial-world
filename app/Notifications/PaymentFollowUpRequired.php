<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The client has just been reminded about a payment. Somebody should call.
 *
 * Sent to the office every time a payment reminder goes to a client, because
 * an unpaid deposit is not a mail problem — it is a conversation somebody has
 * to have. The client gets three reminders, 36 hours apart, and then the mail
 * stops; ops gets the same three prompts, each one naming the job, the amount
 * and how long it has been outstanding, so the follow-up happens while the
 * reminder is still fresh in the client's inbox rather than a month later when
 * somebody notices the job never started.
 */
class PaymentFollowUpRequired extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private PaymentRequest $paymentRequest,
        private \Carbon\CarbonInterface $awaitingSince,
        private int $reminderNumber,
        private int $reminderLimit,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $job = $this->paymentRequest->serviceRequest;
        $ref = $job?->request_id ?? 'a job';
        $client = $job?->user?->name ?? 'the client';
        $waiting = $this->awaitingSince->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2);

        $message = (new MailMessage)
            ->subject("Follow up: {$ref} payment outstanding {$waiting}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Reminder {$this->reminderNumber} of {$this->reminderLimit} has just gone to **{$client}** about an outstanding payment on **{$ref}**.")
            ->line('**Reference:** ' . $this->paymentRequest->payment_request_id)
            ->line('**Amount due:** KES ' . number_format((float) $this->paymentRequest->amount, 2))
            ->line("**Outstanding for:** {$waiting}");

        if ($job?->user?->phone) {
            $message->line('**Client phone:** ' . $job->user->phone);
        }

        // Said plainly on the last one: after this, nothing further is sent and
        // the job simply sits there unless somebody acts.
        $message->line($this->reminderNumber >= $this->reminderLimit
            ? 'This was the last reminder the client will receive. Nothing more goes out automatically — '
                . 'the job stays where it is until somebody speaks to them.'
            : 'A call now is worth more than the next email. The client is reminded again in 36 hours.');

        return $message
            ->action('Open the job', url('/admin/jobs/' . ($job?->id ?? '')))
            ->line('You are receiving this because the payment is still outstanding on an approved job.');
    }

    public function toArray(object $notifiable): array
    {
        $job = $this->paymentRequest->serviceRequest;

        return [
            'type' => 'payment_follow_up_required',
            'service_request_id' => $job?->id,
            'request_id' => $job?->request_id,
            'payment_request_id' => $this->paymentRequest->id,
            'amount' => (float) $this->paymentRequest->amount,
            'reminder_number' => $this->reminderNumber,
            'reminder_limit' => $this->reminderLimit,
            'url' => '/admin/jobs/' . ($job?->id ?? ''),
            'message' => sprintf(
                '%s — client reminded (%d of %d) about KES %s outstanding. Follow up.',
                $job?->request_id ?? 'Job',
                $this->reminderNumber,
                $this->reminderLimit,
                number_format((float) $this->paymentRequest->amount, 2)
            ),
        ];
    }
}
