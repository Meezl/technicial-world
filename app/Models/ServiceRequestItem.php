<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a request: what was asked for, and what it costs.
 *
 * See the create_service_request_items_table migration for why the ask and the
 * price share a row, and why the rates are copied rather than joined.
 */
class ServiceRequestItem extends Model
{
    protected $fillable = [
        'service_request_id', 'rate_item_id', 'kind',
        'code', 'description', 'unit', 'quantity', 'urgency', 'location_detail',
        'planned_start', 'planned_end',
        'material_rate', 'labour_rate', 'transport_rate',
        'consumable_rate', 'overhead_rate', 'margin_rate', 'composite_rate',
        'line_total', 'is_priced',
        'assigned_technician_id', 'released_to_technician_at', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'planned_start' => 'date',
        'planned_end' => 'date',
        'material_rate' => 'decimal:2',
        'labour_rate' => 'decimal:2',
        'transport_rate' => 'decimal:2',
        'consumable_rate' => 'decimal:2',
        'overhead_rate' => 'decimal:2',
        'margin_rate' => 'decimal:2',
        'composite_rate' => 'decimal:2',
        'line_total' => 'decimal:2',
        'is_priced' => 'boolean',
        'released_to_technician_at' => 'datetime',
    ];

    protected $attributes = [
        'kind' => self::KIND_CATALOGUE,
        'unit' => RateItem::UNIT_NUMBER,
        'quantity' => 1,
        'urgency' => 'medium',
        'is_priced' => false,
    ];

    protected $appends = ['unit_label'];

    /** Picked from the rate schedule. */
    const KIND_CATALOGUE = 'catalogue';

    /** Added by the office at the bottom — permits, night-shift allowances. */
    const KIND_ANCILLARY = 'ancillary';

    /**
     * The line total follows from the rate and the quantity, always.
     *
     * Same reasoning as the composite on a rate item: a stored total that can
     * be set independently of what it totals is one that will drift.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->composite_rate = round(array_sum(array_map(
                fn($component) => (float) $item->{$component},
                array_keys(RateItem::COMPONENTS)
            )), 2);

            $item->line_total = round((float) $item->composite_rate * (float) $item->quantity, 2);
        });
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function rateItem(): BelongsTo
    {
        return $this->belongsTo(RateItem::class);
    }

    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'assigned_technician_id');
    }

    public function scopePriced($query)
    {
        return $query->where('is_priced', true);
    }

    /** Null-safe for the same reason as RateItem's — see the note there. */
    public function getUnitLabelAttribute(): string
    {
        return RateItem::UNITS[$this->unit] ?? (string) ($this->unit ?? '');
    }

    /** Is this line open to the technician it belongs to? */
    public function isOpenTo(?int $technicianId): bool
    {
        return $technicianId !== null
            && (int) $this->assigned_technician_id === $technicianId
            && $this->released_to_technician_at !== null;
    }
}
