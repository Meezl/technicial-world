<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketStatusLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the filer every time the admin advances the ticket status.
 */
class TicketStatusChanged extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public TicketStatusLog $log,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Ticket {$this->ticket->ticket_ref} — {$this->ticket->statusLabel()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-status-changed',
            with: [
                'ticket' => $this->ticket,
                'log'    => $this->log,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
