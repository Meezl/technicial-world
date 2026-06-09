<?php

namespace App\Mail;

use App\Models\ServiceRequest;
use App\Models\Technician;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the client when a job is reassigned to a different technician
 * (#23). The email shows the previous and new technician names and the
 * reason captured at reassignment time.
 */
class JobReassigned extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceRequest $serviceRequest,
        public ?Technician   $previousTechnician,
        public Technician    $newTechnician,
        public ?string       $reason,
    ) {}

    public function envelope(): Envelope
    {
        $ref = $this->serviceRequest->request_id ?: ('REQ-' . $this->serviceRequest->id);
        return new Envelope(
            subject: "Job {$ref} — Technician Reassignment",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.job-reassigned',
            with: [
                'serviceRequest'     => $this->serviceRequest,
                'previousTechnician' => $this->previousTechnician,
                'newTechnician'      => $this->newTechnician,
                'reason'             => $this->reason,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
