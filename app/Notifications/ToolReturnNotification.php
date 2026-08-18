<?php

namespace App\Notifications;

use App\Models\ToolIssuance;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The PPE return handshake: a technician requests a return (office is told), and
 * the office confirms or rejects it (the technician is told). Confirmation is
 * what actually restocks — see ToolIssuance::confirmReturn.
 *
 * Not queued, so the in-app record lands immediately; the caller guards the
 * mail attempt.
 */
class ToolReturnNotification extends Notification
{
    use Queueable;

    const ACTION_REQUESTED = 'requested';
    const ACTION_CONFIRMED = 'confirmed';
    const ACTION_REJECTED = 'rejected';

    public function __construct(
        public ToolIssuance $issuance,
        public string $action,
        public int $quantity,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isOps = $this->action === self::ACTION_REQUESTED;

        return (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . '!')
            ->line($this->headline())
            ->action($isOps ? 'Review returns' : 'View my tools',
                url($isOps ? '/admin/tools' : '/technician/tools'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'tool_return',
            'action' => $this->action,
            'tool_issuance_id' => $this->issuance->id,
            'item_name' => $this->itemName(),
            'quantity' => $this->quantity,
            'technician_name' => $this->issuance->technician?->user?->name,
            'message' => $this->headline(),
            'url' => $this->action === self::ACTION_REQUESTED ? '/admin/tools' : '/technician/tools',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    private function itemName(): string
    {
        return $this->issuance->tool?->name ?? 'PPE';
    }

    private function subjectLine(): string
    {
        return match ($this->action) {
            self::ACTION_CONFIRMED => 'PPE return confirmed: ' . $this->itemName(),
            self::ACTION_REJECTED => 'PPE return not confirmed: ' . $this->itemName(),
            default => 'PPE return to confirm: ' . $this->itemName(),
        };
    }

    private function headline(): string
    {
        $name = $this->itemName();
        $technician = $this->issuance->technician?->user?->name ?? 'A technician';

        return match ($this->action) {
            self::ACTION_CONFIRMED => "Your return of {$this->quantity} × {$name} has been confirmed and put back into stock.",
            self::ACTION_REJECTED => "Your return of {$this->quantity} × {$name} was not confirmed — it is still recorded against you. Please check with the office.",
            default => "{$technician} wants to return {$this->quantity} × {$name}. Confirm it once you have the items.",
        };
    }
}
