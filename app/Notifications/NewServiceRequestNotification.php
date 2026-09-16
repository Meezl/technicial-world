<?php

namespace App\Notifications;

use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A client has raised a request — sent to admins, PMs and the office inbox.
 *
 * The same notification carries the two-hourly reminders: `$reminder` is 0 for
 * the first alert and counts up from 1 for each reminder after it.
 */
class NewServiceRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected ServiceRequest $serviceRequest,
        protected int $reminder = 0,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // The office inbox is an address, not a user — it has no database row
        // to write a notification to.
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sr = $this->serviceRequest;

        $message = (new MailMessage)
            ->subject($this->isReminder()
                ? "Reminder: {$sr->request_id} has not been acted on — waiting {$this->waitingFor()}"
                : "New Service Request - {$sr->request_id}")
            ->greeting($notifiable instanceof User ? "Hello {$notifiable->name}," : 'Hello Team,');

        if ($this->isReminder()) {
            $message->line("**{$sr->request_id}** was submitted {$this->waitingFor()} ago and nobody has acted on it yet. The client was told it would be acted on within 2 hours.");
        } else {
            $message->line('A new service request has been submitted and requires your attention. The client has been told it will be acted on within 2 hours.');
        }

        $message
            ->line('**Request Details:**')
            ->line('Request ID: ' . $sr->request_id)
            ->line('Service Category: ' . ($sr->serviceCategory->name ?? 'Not specified'))
            ->line('Client: ' . ($sr->user->name ?? 'Unknown'))
            ->line('Location: ' . $sr->location)
            ->line('Urgency: ' . ucfirst((string) $sr->urgency))
            ->line('Description: ' . $sr->description);

        return $message
            ->action('View Request', $this->link($notifiable))
            ->line($this->isReminder()
                ? 'Assign a PM, quote it or decline it to stop these reminders.'
                : 'Please review and assign it as soon as possible.');
    }

    public function toDatabase(object $notifiable): array
    {
        $sr = $this->serviceRequest;

        return [
            'type' => $this->isReminder() ? 'new_service_request_reminder' : 'new_service_request',
            'service_request_id' => $sr->id,
            'request_id' => $sr->request_id,
            'client_name' => $sr->user->name ?? null,
            'service_category' => $sr->serviceCategory->name ?? 'Not specified',
            'urgency' => $sr->urgency,
            'location' => $sr->location,
            'reminder' => $this->reminder,
            'message' => $this->isReminder()
                ? "{$sr->request_id} still not acted on after {$this->waitingFor()}"
                : 'New service request from ' . ($sr->user->name ?? 'a client'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    private function isReminder(): bool
    {
        return $this->reminder > 0;
    }

    private function waitingFor(): string
    {
        return $this->serviceRequest->created_at
            ? $this->serviceRequest->created_at->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2)
            : ($this->reminder * 2) . ' hours';
    }

    private function link(object $notifiable): string
    {
        return $notifiable instanceof User && $notifiable->role === User::ROLE_PROJECT_MANAGER
            ? url('/pm/rfqs')
            : url('/admin/rfq');
    }
}
