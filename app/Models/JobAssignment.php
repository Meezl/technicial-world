<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobAssignment extends Model
{
    protected $fillable = [
        'service_request_id',
        'service_sub_task_id',
        'technician_id',
        'assigned_by',
        'agreed_compensation',
        'compensation_notes',
        'status',
        'expected_start',
        'expected_end',
        'actual_start',
        'actual_end',
        'reassignment_reason',
        'reassigned_from',
    ];

    protected $casts = [
        'agreed_compensation' => 'decimal:2',
        'expected_start' => 'datetime',
        'expected_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_REASSIGNED = 'reassigned';
    const STATUS_COMPLETED = 'completed';

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function subTask(): BelongsTo
    {
        return $this->belongsTo(ServiceSubTask::class, 'service_sub_task_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function previousTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'reassigned_from');
    }
}
