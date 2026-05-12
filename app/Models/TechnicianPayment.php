<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TechnicianPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'technician_id',
        'service_request_id',
        'progress_report_id',
        'category',
        'amount',
        'status',
        'payment_method',
        'transaction_reference',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function progressReport()
    {
        return $this->belongsTo(ProgressReport::class);
    }
}
