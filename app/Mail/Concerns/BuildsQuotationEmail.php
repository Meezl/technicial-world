<?php

namespace App\Mail\Concerns;

use App\Models\ServiceRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Shared building blocks for the quotation emails, so a first quotation and a
 * revision carry exactly the same content and attachments — the generated
 * quotation PDF, every file the office attached when quoting, and the files the
 * client sent with the original request — and both BCC the office inbox.
 *
 * Expects a public `$serviceRequest` property on the mailable.
 */
trait BuildsQuotationEmail
{
    /**
     * Office addresses BCC'd on the quotation, so a copy of exactly what the
     * client got lands in the shared inbox.
     *
     * @return array<int, string>
     */
    protected function quotationBcc(): array
    {
        return array_values(array_filter(
            (array) config('services.quotation_bcc'),
            fn ($address) => is_string($address) && trim($address) !== '',
        ));
    }

    /**
     * The quotation PDF, every admin-attached materials file, and the client's
     * own RFQ files. Each attachment is best-effort — a single missing file is
     * logged and skipped rather than failing the whole send.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    protected function quotationAttachments(): array
    {
        $sr = $this->serviceRequest;
        $attachments = [];
        $ref = $sr->request_id ?? $sr->id;

        // 1. Generated PDF of the quotation (mirrors the email body).
        try {
            $milestones = $sr->milestones()
                ->orderBy('progress_step')
                ->get(['progress_step', 'amount', 'notes', 'status']);

            $pdf = Pdf::loadView('pdf.quotation', [
                'serviceRequest' => $sr,
                'materials'      => $sr->quote_materials ?? [],
                'laborCost'      => $sr->quote_labor_cost ?? 0,
                'transportCost'  => $sr->quote_transport_cost ?? 0,
                'downPayment'    => $sr->quote_down_payment ?? 0,
                'totalAmount'    => $sr->quote_amount ?? 0,
                'notes'          => $sr->quote_notes,
                'milestones'     => $milestones,
                'mpesaPaybill'   => config('services.mpesa.shortcode'),
                'bank'           => config('services.bank'),
            ]);

            $attachments[] = Attachment::fromData(fn () => $pdf->output(), "Quotation-{$ref}.pdf")
                ->withMime('application/pdf');
        } catch (\Throwable $e) {
            Log::warning('Quotation PDF generation failed', [
                'service_request_id' => $sr->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Every file the office attached when quoting — the multi-file array
        //    plus the legacy single path, de-duplicated. Previously only the
        //    single legacy file went out, so extra documents were dropped.
        $materialsPaths = array_values(array_unique(array_filter(array_merge(
            (array) ($sr->quote_materials_file_paths ?? []),
            [$sr->quote_materials_file_path],
        ))));

        $disk = Storage::disk('public');
        foreach ($materialsPaths as $index => $path) {
            try {
                if (!$disk->exists($path)) {
                    continue;
                }
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $suffix = count($materialsPaths) > 1 ? '-' . ($index + 1) : '';
                $name = "Materials-{$ref}{$suffix}" . ($ext ? ".{$ext}" : '');
                $attachments[] = Attachment::fromStorageDisk('public', $path)->as($name);
            } catch (\Throwable $e) {
                Log::warning('Quotation materials file attach failed', [
                    'service_request_id' => $sr->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. Files the client uploaded with the original RFQ — context for what
        //    the quote is for.
        foreach ((array) ($sr->files ?? []) as $file) {
            if (empty($file['path'])) {
                continue;
            }
            try {
                if ($disk->exists($file['path'])) {
                    $attachments[] = Attachment::fromStorageDisk('public', $file['path'])
                        ->as($file['name'] ?? basename($file['path']));
                }
            } catch (\Throwable $e) {
                Log::warning('Quotation client file attach failed', [
                    'service_request_id' => $sr->id,
                    'path' => $file['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $attachments;
    }
}
