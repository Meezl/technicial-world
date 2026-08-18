<?php

namespace App\Notifications;

use App\Models\ToolRequestItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a technician when the office acts on one of their requested items:
 * accepted (approved, to be issued), assigned (issued to them now), or
 * rejected (with a reason).
 *
 * Not queued for the same reason as the submission notification — the in-app
 * record must be written there and then.
 */
class ToolRequestDecisionNotification extends Notification
{
    use Queueable;

    const ACTION_ACCEPTED = 'accepted';
    const ACTION_ASSIGNED = 'assigned';
    const ACTION_REJECTED = 'rejected';

    public function __construct(
        public ToolRequestItem $item,
        public string $action,
        public ?string $issuedSummary = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->itemName();

        $mail = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . '!')
            ->line($this->headline());

        if ($this->action === self::ACTION_ASSIGNED && $this->issuedSummary) {
            $mail->line('Issued: ' . $this->issuedSummary);
        }

        if ($this->item->decision_notes) {
            $label = $this->action === self::ACTION_REJECTED ? 'Reason' : 'Note';
            $mail->line($label . ': ' . $this->item->decision_notes);
        }

        return $mail->action('View my tools', url('/technician/tools'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'tool_request_decision',
            'action' => $this->action,
            'tool_request_item_id' => $this->item->id,
            'item_name' => $this->itemName(),
            'issued_summary' => $this->issuedSummary,
            'decision_notes' => $this->item->decision_notes,
            'message' => $this->headline(),
            'url' => '/technician/tools',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    private function itemName(): string
    {
        return $this->item->tool?->name ?? $this->item->tool_name_requested;
    }

    private function subjectLine(): string
    {
        return match ($this->action) {
            self::ACTION_ASSIGNED => 'Tool issued: ' . $this->itemName(),
            self::ACTION_REJECTED => 'Tool request declined: ' . $this->itemName(),
            default => 'Tool request accepted: ' . $this->itemName(),
        };
    }

    private function headline(): string
    {
        $name = $this->itemName();

        return match ($this->action) {
            self::ACTION_ASSIGNED => "Your request for {$name} has been approved and issued to you.",
            self::ACTION_REJECTED => "Your request for {$name} was declined.",
            default => "Your request for {$name} has been accepted and will be issued shortly.",
        };
    }
}
