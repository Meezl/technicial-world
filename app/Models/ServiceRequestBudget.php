<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceRequestBudget extends Model
{
    protected $fillable = [
        'service_request_id',
        'labor_budget',
        'materials_budget',
        'other_budget',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'labor_budget' => 'decimal:2',
        'materials_budget' => 'decimal:2',
        'other_budget' => 'decimal:2',
    ];

    protected $appends = ['total_budget'];

    public function getTotalBudgetAttribute(): float
    {
        return (float) $this->labor_budget + (float) $this->materials_budget + (float) $this->other_budget;
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
