<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensationAmendment extends Model
{
    protected $fillable = [
        'service_request_id',
        'variation_order_id',
        'technician_id',
        'requested_by',
        'original_amount',
        'proposed_amount',
        'justification',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'proposed_amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /**
     * The scope change this fee movement relates to. Null for corrections
     * that have no variation behind them.
     */
    public function variationOrder(): BelongsTo
    {
        return $this->belongsTo(VariationOrder::class);
    }

    /** Signed movement — negative when the fee is being reduced. */
    public function delta(): float
    {
        return round((float) $this->proposed_amount - (float) $this->original_amount, 2);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
