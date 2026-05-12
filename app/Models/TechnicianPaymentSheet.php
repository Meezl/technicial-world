<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicianPaymentSheet extends Model
{
    protected $fillable = [
        'sheet_reference',
        'period_start',
        'period_end',
        'created_by',
        'status',
        'finalized_at',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'finalized_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    const STATUS_DRAFT = 'draft';
    const STATUS_FINALIZED = 'finalized';

    public static function generateReference(): string
    {
        $prefix = 'WPS';
        $date = now()->format('Ymd');
        $count = self::whereDate('created_at', today())->count() + 1;
        return "{$prefix}-{$date}-" . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TechnicianPaymentEntry::class, 'payment_sheet_id');
    }

    public function recalculateTotal(): void
    {
        $this->update([
            'total_amount' => $this->entries()->sum('current_period_payable'),
        ]);
    }
}
