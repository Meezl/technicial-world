<?php

namespace App\Notifications;

use App\Models\TechnicianLead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewTechnicianLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private TechnicianLead $lead) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Technician Interest Submission')
            ->greeting('New Technician Lead!')
            ->line("A prospective technician has submitted their interest.")
            ->line("**Name:** {$this->lead->name}")
            ->line("**Trade:** {$this->lead->trade}")
            ->line("**Phone:** {$this->lead->phone}")
            ->line("**Email:** {$this->lead->email}")
            ->line("**Location:** {$this->lead->location}")
            ->action('View Leads', url('/admin/technician-leads'))
            ->line('Please follow up with this prospect.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'name' => $this->lead->name,
            'trade' => $this->lead->trade,
            'message' => "New technician interest from {$this->lead->name} ({$this->lead->trade})",
        ];
    }
}
