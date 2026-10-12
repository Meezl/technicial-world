<?php

namespace App\Mail;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The client's proof that their approval landed.
 *
 * Approving sent them nothing at all. The only record was a row in our own
 * database, which is no use to the person on the other end of an argument
 * about whether they agreed — and "I approved it" against "we never received
 * it" is an argument with no evidence on the client's side and every incentive
 * for both parties to be sure they are right.
 *
 * A receipt ends that class of dispute: they have the figure, the moment and
 * the reference in their own inbox, and so does the office.
 */
class QuotationApprovalReceipt extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceRequest $serviceRequest,
        /** How they agreed — a portal approval or a deposit settled. */
        public string $channel = 'client_portal',
    ) {
    }

    public function envelope(): Envelope
    {
        $ref = $this->serviceRequest->request_id ?: ('REQ-' . $this->serviceRequest->id);

        return new Envelope(
            subject: "Approval received — {$ref} — Technician World",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-approval-receipt',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'reference' => $this->serviceRequest->request_id ?: ('REQ-' . $this->serviceRequest->id),
                'amount' => $this->serviceRequest->approved_quote_amount ?? $this->serviceRequest->quote_amount,
                'approvedAt' => $this->serviceRequest->client_quote_approved_at,
                'paidInstead' => $this->channel === 'client_payment',
            ]
        );
    }
}
