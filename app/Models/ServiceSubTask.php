<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceSubTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_request_id',
        'title',
        'description',
        'technician_id',
        'status',
        'progress_percentage',
        'assigned_at',
        'completed_at',
        'order',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress_percentage' => 'integer',
        'order' => 'integer',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_ASSIGNED = 'assigned';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function complete()
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'progress_percentage' => 100,
            'completed_at' => now(),
        ]);

        $this->serviceRequest->recalculateProgress();
    }
}
