<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line on a variation order. Same shape and vocabulary as
 * QuotationLineItem — material / labor / transport — so a variation reads
 * like the quote it amends.
 *
 * Signed: a negative unit price expresses a deduction, which is how "slots
 * for both the pluses and the minuses" falls out without a second mechanism.
 */
class VariationOrderItem extends Model
{
    protected $fillable = [
        'variation_order_id', 'category', 'description',
        'quantity', 'unit', 'unit_price', 'total_price', 'sort_order',
    ];

    protected $casts = [
        'quantity'    => 'decimal:2',
        'unit_price'  => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    const CATEGORY_MATERIAL  = 'material';
    const CATEGORY_LABOR     = 'labor';
    const CATEGORY_TRANSPORT = 'transport';

    const CATEGORIES = [
        self::CATEGORY_MATERIAL,
        self::CATEGORY_LABOR,
        self::CATEGORY_TRANSPORT,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->total_price = round((float) $item->quantity * (float) $item->unit_price, 2);
        });
    }

    public function variationOrder(): BelongsTo
    {
        return $this->belongsTo(VariationOrder::class);
    }
}
