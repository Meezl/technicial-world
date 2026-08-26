<?php

namespace App\Mail;

use App\Mail\Concerns\BuildsQuotationEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\ServiceRequest;

class QuotationSent extends Mailable
{
    use Queueable, SerializesModels, BuildsQuotationEmail;

    public $serviceRequest;

    /**
     * Create a new message instance.
     */
    public function __construct(ServiceRequest $serviceRequest)
    {
        $this->serviceRequest = $serviceRequest;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Quotation for Your Service Request - Technician World',
            bcc: $this->quotationBcc(),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // #21 — surface defined payment milestones on the quotation so the
        // client knows the staged billing plan up-front.
        $milestones = $this->serviceRequest->milestones()
            ->orderBy('progress_step')
            ->get(['progress_step', 'amount', 'notes', 'status']);

        return new Content(
            view: 'emails.quotation',
            with: [
                'serviceRequest' => $this->serviceRequest,
                'materials' => $this->serviceRequest->quote_materials,
                'laborCost' => $this->serviceRequest->quote_labor_cost,
                'transportCost' => $this->serviceRequest->quote_transport_cost ?? 0,
                'downPayment' => $this->serviceRequest->quote_down_payment,
                'totalAmount' => $this->serviceRequest->quote_amount,
                'notes' => $this->serviceRequest->quote_notes,
                'mpesaPaybill' => config('services.mpesa.shortcode'),
                'mpesaAccountRef' => $this->serviceRequest->request_id,
                'bank' => config('services.bank'),
                'milestones' => $milestones,
            ]
        );
    }

    /**
     * The quotation PDF, every admin-attached file and the client's own RFQ
     * files — built in the shared trait so a revision carries the same set.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return $this->quotationAttachments();
    }
}