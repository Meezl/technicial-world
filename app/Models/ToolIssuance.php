<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hand-out of a stock item (PPE) to a technician. Quantities can be
 * returned in parts, so the row tracks how many went out and how many have
 * come back; the difference is what the technician still holds.
 */
class ToolIssuance extends Model
{
    const STATUS_ISSUED = 'issued';
    const STATUS_PARTIALLY_RETURNED = 'partially_returned';
    const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'tool_id',
        'technician_id',
        'service_request_id',
        'tool_request_item_id',
        'issued_by',
        'quantity',
        'quantity_returned',
        'status',
        'issued_at',
        'expected_return_date',
        'returned_at',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'quantity_returned' => 'integer',
        'issued_at' => 'datetime',
        'expected_return_date' => 'date',
        'returned_at' => 'datetime',
    ];

    protected $appends = ['quantity_outstanding'];

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function toolRequestItem(): BelongsTo
    {
        return $this->belongsTo(ToolRequestItem::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** How many of this issue the technician still holds. */
    public function getQuantityOutstandingAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->quantity_returned);
    }

    public function isFullyReturned(): bool
    {
        return $this->quantity_outstanding === 0;
    }

    /** Issues with anything still out. */
    public function scopeOutstanding($query)
    {
        return $query->whereColumn('quantity_returned', '<', 'quantity');
    }
}
