<?php

namespace App\Mail;

use App\Models\VariationOrder;
use App\Services\BillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The variation card.
 *
 * Deliberately NOT a re-issued quotation. It carries one figure — what
 * changes — plus the reason, any effect on timing, and what the job would be
 * worth if the client agrees. A client who had settled KES 72,000 and owed
 * 7,500 was previously sent the whole 79,500 quotation to approve, and read
 * it as being asked for the lot again.
 */
class VariationOrderIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public VariationOrder $variationOrder,
        protected BillingService $billing,
    ) {
    }

    public function envelope(): Envelope
    {
        $vo = $this->variationOrder;
        $verb = $vo->isDeduction() ? 'Reduction' : 'Additional work';

        return new Envelope(
            // The reference leads so it is obvious this is a change to an
            // existing job, not a fresh quotation for the whole thing.
            subject: "{$vo->vo_number} — {$verb} on your job {$vo->serviceRequest->request_id}",
        );
    }

    public function content(): Content
    {
        $vo = $this->variationOrder;
        $current = $this->billing->contractValue($vo->serviceRequest);

        return new Content(
            view: 'emails.variation-order',
            with: [
                'vo'             => $vo,
                'serviceRequest' => $vo->serviceRequest,
                'items'          => $vo->items,
                'netAmount'      => (float) $vo->net_amount,
                'isDeduction'    => $vo->isDeduction(),
                'currentValue'   => $current,
                'projectedValue' => round($current + (float) $vo->net_amount, 2),
                'settled'        => $this->billing->settled($vo->serviceRequest),
                'reviewUrl'      => route('client.request-status', $vo->serviceRequest),
            ]
        );
    }
}
