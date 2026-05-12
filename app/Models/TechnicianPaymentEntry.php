<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianPaymentEntry extends Model
{
    protected $fillable = [
        'payment_sheet_id',
        'technician_id',
        'service_request_id',
        'agreed_compensation',
        'cumulative_progress_pct',
        'cumulative_amount_due',
        'previous_cumulative_paid',
        'current_period_payable',
        'status',
    ];

    protected $casts = [
        'agreed_compensation' => 'decimal:2',
        'cumulative_amount_due' => 'decimal:2',
        'previous_cumulative_paid' => 'decimal:2',
        'current_period_payable' => 'decimal:2',
        'cumulative_progress_pct' => 'integer',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_PAID = 'paid';

    public function paymentSheet(): BelongsTo
    {
        return $this->belongsTo(TechnicianPaymentSheet::class, 'payment_sheet_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }
}
