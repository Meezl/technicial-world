<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMilestoneAllocation extends Model
{
    protected $fillable = [
        'payment_milestone_id',
        'technician_id',
        'allocated_amount',
        'notes',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(PaymentMilestone::class, 'payment_milestone_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
