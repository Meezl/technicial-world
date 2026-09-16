<?php

namespace App\Mail;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation sent to the client right after they submit a request.
 */
class ServiceRequestReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ServiceRequest $serviceRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your request {$this->serviceRequest->request_id} has been received — Technician World",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.service-request-received',
            with: ['serviceRequest' => $this->serviceRequest],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
