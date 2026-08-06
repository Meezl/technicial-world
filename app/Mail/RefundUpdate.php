<?php

namespace App\Mail;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Telling a client about money owed back to them.
 *
 * One mailable rather than two, because the refund's own status is the state
 * being reported — approved means we have accepted we owe it, settled means
 * it has gone. Splitting them into separate classes would duplicate a
 * template to say almost the same thing.
 *
 * A credit note is deliberately worded differently: no money is coming, it is
 * held against the job. Telling someone a payment is on its way when it is
 * not would be worse than saying nothing.
 */
class RefundUpdate extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Refund $refund)
    {
    }

    public function envelope(): Envelope
    {
        $ref = $this->refund->serviceRequest->request_id;

        $subject = match (true) {
            $this->refund->status === Refund::STATUS_SETTLED
                => "Refund sent for your job {$ref}",
            $this->refund->isCreditNote()
                => "Credit applied to your job {$ref}",
            default
                => "Refund approved for your job {$ref}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.refund-update',
            with: [
                'refund'         => $this->refund,
                'serviceRequest' => $this->refund->serviceRequest,
                'amount'         => (float) $this->refund->amount,
                'isSettled'      => $this->refund->status === Refund::STATUS_SETTLED,
                'isCreditNote'   => $this->refund->isCreditNote(),
                'reviewUrl'      => route('client.request-status', $this->refund->serviceRequest),
            ]
        );
    }
}
