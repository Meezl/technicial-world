<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A quotation or an approved variation, as it appeared when billed. */
class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'variation_order_id', 'kind', 'reference',
        'description', 'requested_by', 'approved_by', 'amount_ex_vat', 'sort_order',
    ];

    protected $casts = ['amount_ex_vat' => 'decimal:2'];

    const KIND_QUOTATION = 'quotation';
    const KIND_VARIATION = 'variation';

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function variationOrder(): BelongsTo
    {
        return $this->belongsTo(VariationOrder::class);
    }
}
