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
        $targets = \App\Services\ResponseTargetService::make();
        $due = $this->serviceRequest->response_due_at
            ?? $targets->dueAtFor($this->serviceRequest);

        return new Content(
            view: 'emails.service-request-received',
            with: [
                'serviceRequest' => $this->serviceRequest,
                // A moment, not a countdown. "Within 2 hours" is false three
                // hours later; "by 10:00 tomorrow" is still true at 3am and
                // is something the client can hold us to.
                'respondBy' => $due,
                'respondByPhrase' => $targets->phrase($due),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
