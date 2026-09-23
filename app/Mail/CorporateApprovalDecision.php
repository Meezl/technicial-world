<?php

namespace App\Mail;

use App\Models\CorporateApproval;
use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * What the client's approver decided, to the office and the PM.
 *
 * One mailable for both outcomes: the recipients are the same people and the
 * question they are answering — "what do I do about this now" — is the same.
 * Splitting it would mean two templates drifting apart on the job details that
 * make up most of both.
 */
class CorporateApprovalDecision extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceRequest $serviceRequest,
        public CorporateApproval $approval,
        public ?string $declineComments = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $declined = $this->declineComments !== null;

        return new Envelope(
            subject: sprintf(
                '%s — %s by %s',
                $this->serviceRequest->quote_reference,
                $declined ? 'DECLINED' : 'approved',
                $this->serviceRequest->organisation?->name ?? 'client'
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.corporate-approval-decision',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'approval' => $this->approval,
                'declineComments' => $this->declineComments,
                'declined' => $this->declineComments !== null,
            ],
        );
    }
}
