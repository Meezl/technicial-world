<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\ServiceRequest;

class JobAssigned extends Mailable
{
    use Queueable, SerializesModels;

    public $serviceRequest;

    /**
     * Create a new message instance.
     */
    public function __construct(ServiceRequest $serviceRequest)
    {
        $this->serviceRequest = $serviceRequest;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Job Assignment - Technician World',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.job-assigned',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'clientName' => $this->serviceRequest->user->name,
                'clientPhone' => $this->serviceRequest->user->phone,
                'location' => $this->serviceRequest->location,
                'description' => $this->serviceRequest->description,
                'urgency' => $this->serviceRequest->urgency,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
