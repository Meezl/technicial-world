<?php

namespace App\Mail;

use App\Models\InvoiceBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The proformas going out when the float trips its threshold. */
class CorporateInvoiceBatchDispatched extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public InvoiceBatch $batch)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf(
            'Proforma invoices %s — %s',
            $this->batch->reference,
            $this->batch->organisation?->name ?? 'your account',
        ));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.corporate-invoice-batch',
            with: ['batch' => $this->batch, 'issuer' => config('corporate.issuer')],
        );
    }
}
