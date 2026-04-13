<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\ServiceRequest;
use App\Models\Technician;

class TechnicianAssigned extends Mailable
{
    use Queueable, SerializesModels;

    public $serviceRequest;
    public $technician;

    /**
     * Create a new message instance.
     */
    public function __construct(ServiceRequest $serviceRequest, Technician $technician)
    {
        $this->serviceRequest = $serviceRequest;
        $this->technician = $technician;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Technician Assigned to Your Request - Technician World',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.technician-assigned',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'technicianName' => $this->technician->user->name,
                'technicianPhone' => $this->technician->user->phone,
                'serviceCategory' => $this->serviceRequest->serviceCategory->name ?? 'General',
                'description' => $this->serviceRequest->description,
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
