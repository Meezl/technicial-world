<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to admin + every project manager when a new ticket is filed.
 */
class TicketCreatedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        $prefix = match ($this->ticket->urgency) {
            Ticket::URGENCY_EMERGENCY => '🚨 EMERGENCY',
            Ticket::URGENCY_URGENT    => '⚠ URGENT',
            default                   => 'New',
        };
        return new Envelope(
            subject: "{$prefix} Ticket: {$this->ticket->ticket_ref} — {$this->ticket->subject}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-created',
            with: ['ticket' => $this->ticket],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
