<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client-billing milestone on a service request: when the job reaches
 * `progress_pct`, bill the client `amount`.
 *
 * Distinct from PaymentMilestone, which releases labour money *to* technicians.
 *
 * `payment_request_id` is the settled marker. Once a milestone has raised its
 * bill the row is closed for good — quote revisions rebuild the unbilled tail
 * of the schedule and leave billed rows untouched.
 */
class ReqBillingMilestone extends Model
{
    protected $fillable = [
        'service_request_id',
        'variation_order_id',
        'label',
        'progress_pct',
        'amount',
        'sort_order',
        'payment_request_id',
        'triggered_at',
    ];

    protected $casts = [
        'progress_pct' => 'decimal:2',
        'amount' => 'decimal:2',
        'triggered_at' => 'datetime',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /**
     * Set when this milestone bills a variation rather than the original
     * quote. Null means it belongs to the quote itself.
     */
    public function variationOrder(): BelongsTo
    {
        return $this->belongsTo(VariationOrder::class);
    }

    public function belongsToVariation(): bool
    {
        return $this->variation_order_id !== null;
    }

    /**
     * Has this milestone already raised its bill? The question the old
     * `triggered` flag was meant to answer, now backed by a foreign key that
     * a revision cannot silently reset.
     */
    public function isBilled(): bool
    {
        return $this->payment_request_id !== null;
    }

    /**
     * Legacy array shape, still consumed by the admin RFQ form and the client
     * request-status page.
     */
    public function toLegacyArray(): array
    {
        return [
            'label'        => $this->label,
            'progress_pct' => (float) $this->progress_pct,
            'amount'       => (float) $this->amount,
            'triggered'    => $this->isBilled(),
        ];
    }
}
