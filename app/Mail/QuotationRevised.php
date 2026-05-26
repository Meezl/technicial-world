<?php

namespace App\Mail;

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
    use Queueable, SerializesModels;

    public $serviceRequest;

    public function __construct(ServiceRequest $serviceRequest)
    {
        $this->serviceRequest = $serviceRequest;
    }

    public function envelope(): Envelope
    {
        $ref = $this->serviceRequest->request_id ?: ('REQ-' . $this->serviceRequest->id);
        return new Envelope(
            subject: "Revised Quotation — {$ref} — Technician World",
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
                'revisionCount'   => $this->serviceRequest->quote_revision_count,
                'mpesaPaybill'    => config('services.mpesa.shortcode'),
                'mpesaAccountRef' => $this->serviceRequest->request_id,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
