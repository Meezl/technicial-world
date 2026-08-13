<?php

namespace App\Mail;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the office a lead has pushed a batch of site reports up for review.
 * The office sees nothing from the crew until this lands, so this is their
 * cue that a job has progress waiting to be settled and released.
 */
class LeadReportsPosted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceRequest $serviceRequest,
        public int $reportCount,
    ) {}

    public function envelope(): Envelope
    {
        $ref = $this->serviceRequest->request_id ?: ('REQ-' . $this->serviceRequest->id);

        return new Envelope(
            subject: "Reports to review — {$ref}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lead-reports-posted',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'reportCount'    => $this->reportCount,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
