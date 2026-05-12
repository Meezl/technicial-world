<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $fillable = [
        'service_request_id',
        'created_by',
        'version',
        'status',
        'materials_total',
        'labor_total',
        'transport_total',
        'grand_total',
        'payment_terms',
        'delivery_timeline',
        'valid_until',
        'notes',
        'materials_file_path',
        'approved_at',
        'declined_at',
        'decline_reason',
    ];

    protected $casts = [
        'materials_total' => 'decimal:2',
        'labor_total' => 'decimal:2',
        'transport_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'payment_terms' => 'array',
        'valid_until' => 'date',
        'approved_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';
    const STATUS_SENT = 'sent';
    const STATUS_APPROVED = 'approved';
    const STATUS_DECLINED = 'declined';
    const STATUS_SUPERSEDED = 'superseded';

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(QuotationLineItem::class)->orderBy('sort_order');
    }

    public function recalculateTotals(): void
    {
        $items = $this->lineItems()->get();

        $this->update([
            'materials_total' => $items->where('category', 'material')->sum('total_price'),
            'labor_total' => $items->where('category', 'labor')->sum('total_price'),
            'transport_total' => $items->where('category', 'transport')->sum('total_price'),
            'grand_total' => $items->sum('total_price'),
        ]);
    }

    public function createRevision(): self
    {
        $this->update(['status' => self::STATUS_SUPERSEDED]);

        $newQuotation = $this->replicate();
        $newQuotation->version = $this->version + 1;
        $newQuotation->status = self::STATUS_DRAFT;
        $newQuotation->approved_at = null;
        $newQuotation->declined_at = null;
        $newQuotation->decline_reason = null;
        $newQuotation->save();

        foreach ($this->lineItems as $item) {
            $newItem = $item->replicate();
            $newItem->quotation_id = $newQuotation->id;
            $newItem->save();
        }

        return $newQuotation;
    }
}
