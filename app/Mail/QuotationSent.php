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
                'bank' => config('services.bank'),
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
        $attachments = [];
        $serviceRequest = $this->serviceRequest;

        // 1. Generated PDF of the quotation (mirrors the email body)
        try {
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
                'mpesaPaybill'   => config('services.mpesa.shortcode'),
                'bank'           => config('services.bank'),
            ]);

            $filename = 'Quotation-' . ($serviceRequest->request_id ?? $serviceRequest->id) . '.pdf';
            $attachments[] = Attachment::fromData(fn () => $pdf->output(), $filename)
                ->withMime('application/pdf');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('QuotationSent PDF generation failed', ['error' => $e->getMessage()]);
        }

        // 2. Admin-uploaded materials file attached when sending the quote
        if ($serviceRequest->quote_materials_file_path) {
            try {
                $disk = \Illuminate\Support\Facades\Storage::disk('public');
                if ($disk->exists($serviceRequest->quote_materials_file_path)) {
                    $attachments[] = Attachment::fromStorageDisk('public', $serviceRequest->quote_materials_file_path)
                        ->as('Materials-' . ($serviceRequest->request_id ?? 'breakdown') . '.' . pathinfo($serviceRequest->quote_materials_file_path, PATHINFO_EXTENSION));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('QuotationSent materials file attach failed', ['error' => $e->getMessage()]);
            }
        }

        // 3. Files the client uploaded with the original RFQ (if any) — gives
        //    context on what the quote is for.
        $files = $serviceRequest->files ?? [];
        if (is_array($files)) {
            foreach ($files as $file) {
                if (empty($file['path'])) continue;
                try {
                    $disk = \Illuminate\Support\Facades\Storage::disk('public');
                    if ($disk->exists($file['path'])) {
                        $attachments[] = Attachment::fromStorageDisk('public', $file['path'])
                            ->as($file['name'] ?? basename($file['path']));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('QuotationSent client file attach failed', ['error' => $e->getMessage(), 'path' => $file['path']]);
                }
            }
        }

        return $attachments;
    }
}