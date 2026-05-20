<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMilestone extends Model
{
    protected $fillable = [
        'service_request_id',
        'progress_step',
        'labor_release_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'labor_release_amount' => 'decimal:2',
        'progress_step' => 'integer',
    ];

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentMilestoneAllocation::class)->orderBy('id');
    }
}
