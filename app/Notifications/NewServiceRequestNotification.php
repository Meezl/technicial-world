<?php

namespace App\Notifications;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\Concerns\RecordsEmailSubject;
use App\Services\ResponseTargetService;
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
    use RecordsEmailSubject;

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

    /**
     * The last word on who receives this, checked as it is sent.
     *
     * The recipients are picked when the alert is queued, but a queued job
     * carries only the user's id; the worker loads whoever has that id in its
     * own database. A client received office reminders about another
     * client's requests that way, without their role ever having changed.
     * Laravel asks shouldSend() in the worker, after that load, so this is
     * the one place that sees the user the email will actually reach.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (!$notifiable instanceof User) {
            return true;
        }

        $allowed = in_array($notifiable->role, [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER], true)
            // Unset on a model that was created in memory and never reloaded;
            // the column defaults to active.
            && (bool) ($notifiable->is_active ?? true);

        if (!$allowed) {
            // Loud on purpose: this means something queued an office alert
            // for somebody who is not office staff, and that is worth finding.
            \Illuminate\Support\Facades\Log::warning('Office alert blocked for non-staff recipient', [
                'user_id' => $notifiable->id,
                'role' => $notifiable->role,
                'is_active' => $notifiable->is_active,
                'service_request_id' => $this->serviceRequest->id,
                'request_id' => $this->serviceRequest->request_id,
                'reminder' => $this->reminder,
                'channel' => $channel,
            ]);
        }

        return $allowed;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sr = $this->serviceRequest;

        $urgency = ucfirst((string) $sr->urgency);
        $targets = ResponseTargetService::make();
        $due = $sr->response_due_at;
        $overdue = $due && now()->gt($due);
        $escalated = $this->reminder >= (int) config('service_levels.escalate_after', 3);

        // The deadline leads, not the elapsed time. "Waiting 16 hours" only
        // tells somebody off; "due by 10:00, 1h 20m left" tells them when to
        // do it — and on a low-urgency job raised overnight those two
        // sentences disagree, because nothing was late at all.
        $message = (new MailMessage)
            ->subject($this->subjectLine($sr, $urgency, $due, $overdue, $escalated))
            ->greeting($notifiable instanceof User ? "Hello {$notifiable->name}," : 'Hello Team,');

        if ($due) {
            $window = $targets->phrase($due);
            $message->line($overdue
                ? "**{$sr->request_id}** ({$urgency} urgency) is past the response we promised the client — it was due {$window}."
                : "**{$sr->request_id}** ({$urgency} urgency) is waiting on the office. The client was told we would come back to them **{$window}**.");
        } else {
            $message->line("**{$sr->request_id}** ({$urgency} urgency) is waiting on the office.");
        }

        if ($escalated) {
            $message->line('This is the ' . $this->ordinal($this->reminder)
                . ' reminder. If it cannot be actioned, decline it so the client stops waiting on an answer that is not coming.');
        }

        $message
            ->line('**Request Details:**')
            ->line('Request ID: ' . $sr->request_id)
            ->line('Urgency: ' . $urgency)
            ->line('Service Category: ' . ($sr->serviceCategory->name ?? 'Not specified'))
            ->line('Client: ' . ($sr->user->name ?? 'Unknown'))
            ->line('Location: ' . $sr->location)
            ->line('Description: ' . $sr->description);

        // Straight to the thing that needs doing. One "View Request" button
        // meant open the email, click, sign in, land on a list, find the row,
        // then find the control — every time, for every reminder.
        $message
            ->action('Open ' . $sr->request_id, $this->link($notifiable))
            ->line('Quote it, assign a PM or decline it — any of the three stops these reminders.');

        // So the archive files this against the job rather than against
        // whichever admin happened to receive it.
        return $this->recordSubject($message, $sr);
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

    /** "3rd", not "3th". */
    private function ordinal(int $n): string
    {
        $suffix = match (true) {
            in_array($n % 100, [11, 12, 13], true) => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };

        return $n . $suffix;
    }

    /**
     * Says what is actually true, in the subject, where it is read.
     */
    private function subjectLine(
        ServiceRequest $sr,
        string $urgency,
        ?\Carbon\CarbonInterface $due,
        bool $overdue,
        bool $escalated
    ): string {
        if (!$this->isReminder()) {
            return "New {$urgency} request — {$sr->request_id}";
        }

        if ($overdue && $escalated) {
            return "Still unanswered: {$sr->request_id} ({$urgency}) is past its response time";
        }

        if ($overdue) {
            return "Overdue: {$sr->request_id} ({$urgency}) is past its response time";
        }

        return $due
            ? "{$sr->request_id} ({$urgency}) — response due " . $due->format('g:ia D')
            : "Reminder: {$sr->request_id} is waiting on the office";
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
