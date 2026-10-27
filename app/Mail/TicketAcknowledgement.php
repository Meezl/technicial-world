<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Mail\Concerns\CopiesTheOfficeArchive;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation sent to the filer right after they create a ticket.
 */
class TicketAcknowledgement extends Mailable
{
    use CopiesTheOfficeArchive, Queueable, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your ticket {$this->ticket->ticket_ref} has been received — Technician World",
            bcc: $this->officeArchive(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-acknowledged',
            with: ['ticket' => $this->ticket],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
