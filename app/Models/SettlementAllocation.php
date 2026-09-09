<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of one payment answers one invoice. */
class SettlementAllocation extends Model
{
    protected $fillable = ['settlement_id', 'invoice_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
