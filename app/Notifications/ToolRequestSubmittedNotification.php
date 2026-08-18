<?php

namespace App\Notifications;

use App\Models\ToolRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to admins when a technician submits a tool / PPE request, so the office
 * knows there is something waiting to approve, reject or issue.
 *
 * Not queued: the database record must land immediately (it is what the office
 * acts on), and the mail attempt is wrapped in a try/catch by the caller.
 */
class ToolRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(public ToolRequest $toolRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $technician = $this->toolRequest->technician?->user?->name ?? 'A technician';

        $mail = (new MailMessage)
            ->subject('New tool request from ' . $technician)
            ->greeting('Hello Administrator!')
            ->line($technician . ' has requested the following:');

        foreach ($this->itemLines() as $line) {
            $mail->line('• ' . $line);
        }

        if ($this->toolRequest->notes) {
            $mail->line('Notes: ' . $this->toolRequest->notes);
        }

        return $mail
            ->line('Urgency: ' . ucfirst($this->toolRequest->urgency ?? 'normal'))
            ->action('Review request', url('/admin/tools'));
    }

    public function toDatabase(object $notifiable): array
    {
        $technician = $this->toolRequest->technician?->user?->name ?? 'A technician';

        return [
            'type' => 'tool_request_submitted',
            'tool_request_id' => $this->toolRequest->id,
            'technician_name' => $technician,
            'urgency' => $this->toolRequest->urgency ?? 'normal',
            'items' => $this->itemLines(),
            'message' => $technician . ' requested ' . $this->itemsSummary(),
            'url' => '/admin/tools',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /** @return array<int,string> */
    private function itemLines(): array
    {
        return $this->toolRequest->items->map(function ($item) {
            $name = $item->tool?->name ?? $item->tool_name_requested;
            return "{$item->quantity} × {$name}";
        })->all();
    }

    private function itemsSummary(): string
    {
        $lines = $this->itemLines();
        if (count($lines) <= 2) {
            return implode(', ', $lines);
        }
        return $lines[0] . ' and ' . (count($lines) - 1) . ' more';
    }
}
