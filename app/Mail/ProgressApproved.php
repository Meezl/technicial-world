<?php

namespace App\Mail;

use App\Models\ProgressReport;
use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProgressApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceRequest $serviceRequest,
        public ProgressReport $report,
    ) {}

    public function envelope(): Envelope
    {
        $ref = $this->serviceRequest->request_id ?: ('REQ-' . $this->serviceRequest->id);
        $pct = $this->report->validated_percent ?? $this->report->percent_complete;
        return new Envelope(
            subject: "Progress Update — {$ref} is {$pct}% complete",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.progress-approved',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'report'         => $this->report,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
