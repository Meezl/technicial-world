<?php

namespace App\Mail;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * One collective progress update to the client, carrying every report the
 * office released together. Replaces the old one-email-per-report behaviour
 * that had a client hearing separately from each sub-technician.
 */
class ProgressBatchReleased extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceRequest $serviceRequest,
        public Collection $reports,
    ) {}

    public function envelope(): Envelope
    {
        $ref = $this->serviceRequest->request_id ?: ('REQ-' . $this->serviceRequest->id);
        $count = $this->reports->count();
        $noun = $count === 1 ? 'update' : 'updates';

        return new Envelope(
            subject: "Progress Update — {$count} {$noun} on {$ref}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.progress-batch-released',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'reports'        => $this->reports,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
