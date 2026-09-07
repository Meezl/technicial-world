<?php

namespace App\Mail;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Who is coming to the client's property, and when.
 *
 * Reproduces the notice the office writes by hand today. The ID numbers are
 * the point of it: site security checks them at the gate, and a technician who
 * turns up unannounced is turned away.
 *
 * Not queued. The office sends this when a crew is about to travel, and a
 * queue failure would leave them believing the client had been told.
 */
class TechnicianAttendanceNotice extends Mailable
{
    use Queueable, SerializesModels;

    public array $roster;
    public array $window;

    public function __construct(
        public ServiceRequest $serviceRequest,
        public ?string $additionalNotes = null,
    ) {
        $this->roster = $serviceRequest->attendanceRoster();
        $this->window = $serviceRequest->attendanceWindow();
    }

    public function envelope(): Envelope
    {
        // Mirrors the subject line the office already uses, so the notice
        // threads with the correspondence the client already has.
        $reference = $this->serviceRequest->request_id;
        $title = strtoupper(trim($this->serviceRequest->description ?? 'SERVICE REQUEST'));

        return new Envelope(
            subject: "{$reference} - {$title} - TECHNICIANS & GANG MEMBERS",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.technician-attendance-notice',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'roster' => $this->roster,
                'window' => $this->window,
                'additionalNotes' => $this->additionalNotes,
            ],
        );
    }
}
