<?php

namespace App\Mail;

use App\Mail\Concerns\BuildsQuotationEmail;
use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an admin sends a revised quotation. Politely informs the
 * client that the previous quotation should be disregarded and the new
 * figures replace it. Used after a discount negotiation, omitted
 * details, or any other reason the admin needs to amend the quote.
 */
class QuotationRevised extends Mailable
{
    use Queueable, SerializesModels, BuildsQuotationEmail;

    public $serviceRequest;

    /**
     * IDs (payment_request_id strings) of any pending payment requests
     * that were cancelled by this revision — listed in the email so the
     * client knows which prior asks to disregard (#4).
     */
    public array $cancelledRequestIds;

    public function __construct(ServiceRequest $serviceRequest, array $cancelledRequestIds = [])
    {
        $this->serviceRequest = $serviceRequest;
        $this->cancelledRequestIds = $cancelledRequestIds;
    }

    public function envelope(): Envelope
    {
        $ref = $this->serviceRequest->request_id ?: ('REQ-' . $this->serviceRequest->id);
        return new Envelope(
            subject: "Revised Quotation — {$ref} — Technician World",
            bcc: $this->quotationBcc(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-revised',
            with: [
                'serviceRequest'  => $this->serviceRequest,
                'materials'       => $this->serviceRequest->quote_materials,
                'laborCost'       => $this->serviceRequest->quote_labor_cost,
                'transportCost'   => $this->serviceRequest->quote_transport_cost ?? 0,
                'downPayment'     => $this->serviceRequest->quote_down_payment,
                'totalAmount'     => $this->serviceRequest->quote_amount,
                'notes'           => $this->serviceRequest->quote_notes,
                // Floor at 1 so the email never displays "Revision #0" — every
                // revision sent IS at least the first revision (#3).
                'revisionCount'   => max(1, (int) $this->serviceRequest->quote_revision_count),
                'mpesaPaybill'    => config('services.mpesa.shortcode'),
                'mpesaAccountRef' => $this->serviceRequest->request_id,
                'cancelledRequestIds' => $this->cancelledRequestIds,
                'bank'            => config('services.bank'),
            ]
        );
    }

    /**
     * A revision carries the same attachments as the first quotation — the
     * regenerated PDF and every attached file — not an empty set as before.
     */
    public function attachments(): array
    {
        return $this->quotationAttachments();
    }
}
