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
        'role_on_job',
        'assigned_by',
        'agreed_compensation',
        'paid_through_lead',
        'compensation_notes',
        'attachments',
        'status',
        'expected_start',
        'expected_end',
        'attendance_dates',
        'actual_start',
        'actual_end',
        'reassignment_reason',
        'reassigned_from',
    ];

    protected $casts = [
        'agreed_compensation' => 'decimal:2',
        'paid_through_lead' => 'boolean',
        'attachments' => 'array',
        'attendance_dates' => 'array',
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

    /**
     * When the client should expect this person, in words.
     *
     * Discrete dates win over the range when they are set: a specialist who
     * comes on the 4th and again on the 8th is not on site for the four days
     * between, and a range would tell the client to expect them throughout.
     */
    public function attendanceLabel(): string
    {
        $dates = collect($this->attendance_dates ?? [])
            ->filter()
            ->map(fn ($date) => \Carbon\Carbon::parse($date))
            ->sort()
            ->map(fn ($date) => $date->format('d.m.Y'))
            ->values();

        if ($dates->isNotEmpty()) {
            return $dates->implode(', ');
        }

        if (!$this->expected_start) {
            return 'To be confirmed';
        }

        $start = $this->expected_start->format('d.m.Y');
        if (!$this->expected_end || $this->expected_end->isSameDay($this->expected_start)) {
            return $start;
        }

        return $start . ' - ' . $this->expected_end->format('d.m.Y');
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
