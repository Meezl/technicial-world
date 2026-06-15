<?php

namespace App\Mail;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\ServiceRequest;

class QuotationSent extends Mailable
{
    use Queueable, SerializesModels;

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
                'milestones' => $milestones,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        try {
            $serviceRequest = $this->serviceRequest;
            $milestones = $serviceRequest->milestones()
                ->orderBy('progress_step')
                ->get(['progress_step', 'amount', 'notes', 'status']);

            $pdf = Pdf::loadView('pdf.quotation', [
                'serviceRequest' => $serviceRequest,
                'materials'      => $serviceRequest->quote_materials ?? [],
                'laborCost'      => $serviceRequest->quote_labor_cost ?? 0,
                'transportCost'  => $serviceRequest->quote_transport_cost ?? 0,
                'downPayment'    => $serviceRequest->quote_down_payment ?? 0,
                'totalAmount'    => $serviceRequest->quote_amount ?? 0,
                'notes'          => $serviceRequest->quote_notes,
                'milestones'     => $milestones,
            ]);

            $filename = 'Quotation-' . ($serviceRequest->request_id ?? $serviceRequest->id) . '.pdf';

            return [
                Attachment::fromData(fn () => $pdf->output(), $filename)
                    ->withMime('application/pdf'),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('QuotationSent PDF generation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}