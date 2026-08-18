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
        'return_pending_quantity',
        'return_requested_at',
        'status',
        'issued_at',
        'expected_return_date',
        'returned_at',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'quantity_returned' => 'integer',
        'return_pending_quantity' => 'integer',
        'return_requested_at' => 'datetime',
        'issued_at' => 'datetime',
        'expected_return_date' => 'date',
        'returned_at' => 'datetime',
    ];

    protected $appends = ['quantity_outstanding', 'quantity_returnable'];

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

    /**
     * How many the technician can still ask to return — what they hold, less
     * anything already awaiting the office's confirmation.
     */
    public function getQuantityReturnableAttribute(): int
    {
        return max(0, $this->quantity_outstanding - (int) $this->return_pending_quantity);
    }

    public function isFullyReturned(): bool
    {
        return $this->quantity_outstanding === 0;
    }

    public function hasPendingReturn(): bool
    {
        return (int) $this->return_pending_quantity > 0;
    }

    /**
     * Technician asks to hand a quantity back. Nothing moves off their name
     * yet — it waits for the office to confirm. Throws if the amount is more
     * than they can still return.
     */
    public function requestReturn(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Return quantity must be at least 1.');
        }
        if ($quantity > $this->quantity_returnable) {
            throw new \RuntimeException('That is more than you can still return on this issue.');
        }

        $this->return_pending_quantity += $quantity;
        $this->return_requested_at = now();
        $this->save();
    }

    /**
     * Office confirms the pending return: the quantity goes back on the shelf
     * (via Tool::restockQuantity) and the pending marker clears.
     */
    public function confirmReturn(): void
    {
        $quantity = (int) $this->return_pending_quantity;
        if ($quantity < 1) {
            throw new \RuntimeException('There is no pending return to confirm.');
        }

        $this->tool->restockQuantity($this, $quantity);

        $this->return_pending_quantity = 0;
        $this->return_requested_at = null;
        $this->save();
    }

    /**
     * Office rejects the pending return — the technician still holds the items,
     * so nothing is restocked; the marker just clears.
     */
    public function rejectReturn(): void
    {
        $this->return_pending_quantity = 0;
        $this->return_requested_at = null;
        $this->save();
    }

    /** Issues with anything still out. */
    public function scopeOutstanding($query)
    {
        return $query->whereColumn('quantity_returned', '<', 'quantity');
    }

    /** Issues with a technician return waiting on the office to confirm. */
    public function scopePendingReturn($query)
    {
        return $query->where('return_pending_quantity', '>', 0);
    }
}
