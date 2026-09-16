<?php

namespace App\Notifications;

use App\Models\ActionReminder;
use App\Models\PaymentRequest;
use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something is waiting on the client, and has been for 12 hours or more.
 *
 * $subject is the job for quote, verification and date reminders, and the
 * payment request for payment reminders.
 */
class ClientActionReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $kind,
        private ServiceRequest|PaymentRequest $subject,
        private \Carbon\CarbonInterface $waitingSince,
        private string $url,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $job = $this->job();
        $ref = $job?->request_id ?? 'your request';
        $waiting = $this->waitingFor();

        $message = (new MailMessage)
            ->subject($this->subjectLine($ref))
            ->greeting('Hello ' . $notifiable->name . ',');

        match ($this->kind) {
            ActionReminder::KIND_QUOTE_DECISION => $message
                ->line("The quotation for **{$ref}** has been waiting for your decision for {$waiting}.")
                ->line('**Quoted amount:** KES ' . number_format((float) $job->quote_amount, 2))
                ->line('Please review it and approve or decline so we can plan the work.'),
            ActionReminder::KIND_PAYMENT => $message
                ->line("A payment request on **{$ref}** has been outstanding for {$waiting}.")
                ->line('**Reference:** ' . $this->subject->payment_request_id)
                ->line('**Amount due:** KES ' . number_format((float) $this->subject->amount, 2))
                ->line('Work is scheduled once payment is received.'),
            ActionReminder::KIND_COMPLETION_VERIFICATION => $message
                ->line("Our team has finished the work on **{$ref}** and has been waiting {$waiting} for you to confirm it.")
                ->line('Please confirm the work is complete, or tell us if anything is not right.'),
            ActionReminder::KIND_DATE_RESPONSE => $message
                ->line("We proposed a date for **{$ref}** and have been waiting {$waiting} for your response.")
                ->line('Please confirm the date or suggest another so we can book the technician.'),
        };

        if ($job) {
            $message->line('**Service:** ' . ($job->serviceCategory->name ?? 'Service request') . ' — ' . $job->location);
        }

        return $message
            ->action($this->actionText(), $this->url)
            ->line('If you have already done this, please ignore this reminder.');
    }

    public function toArray(object $notifiable): array
    {
        $job = $this->job();

        return [
            'type' => 'client_action_reminder',
            'kind' => $this->kind,
            'service_request_id' => $job?->id,
            'request_id' => $job?->request_id,
            'payment_request_id' => $this->subject instanceof PaymentRequest ? $this->subject->id : null,
            'url' => $this->url,
            'message' => $this->subjectLine($job?->request_id ?? 'your request'),
        ];
    }

    private function job(): ?ServiceRequest
    {
        return $this->subject instanceof PaymentRequest ? $this->subject->serviceRequest : $this->subject;
    }

    private function subjectLine(string $ref): string
    {
        return match ($this->kind) {
            ActionReminder::KIND_QUOTE_DECISION => "Reminder: your quotation for {$ref} is awaiting your decision",
            ActionReminder::KIND_PAYMENT => "Reminder: payment outstanding for {$ref}",
            ActionReminder::KIND_COMPLETION_VERIFICATION => "Reminder: please confirm the work on {$ref}",
            ActionReminder::KIND_DATE_RESPONSE => "Reminder: please respond to the proposed date for {$ref}",
        };
    }

    private function actionText(): string
    {
        return match ($this->kind) {
            ActionReminder::KIND_QUOTE_DECISION => 'Review the quotation',
            ActionReminder::KIND_PAYMENT => 'Make payment',
            ActionReminder::KIND_COMPLETION_VERIFICATION => 'Confirm the work',
            ActionReminder::KIND_DATE_RESPONSE => 'Respond now',
        };
    }

    private function waitingFor(): string
    {
        return $this->waitingSince->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2);
    }
}
