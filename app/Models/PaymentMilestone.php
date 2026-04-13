<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMilestone extends Model
{
    protected $fillable = [
        'service_request_id',
        'progress_step',
        'amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'progress_step' => 'integer',
    ];

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }
}
