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
        'agreed_compensation',
        'compensation_notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress_percentage' => 'integer',
        'order' => 'integer',
        'agreed_compensation' => 'decimal:2',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_ASSIGNED = 'assigned';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';

    /**
     * Keep the headline status and the progress bar from ever disagreeing.
     *
     * The bar on the job page reads progress_percentage directly while the
     * badge reads status, so a row saved completed at anything under 100 — as
     * older rollups left some — renders "Completed" over a part-full bar. This
     * guard closes that gap at the source: whatever path marks a sub-task
     * completed, it leaves here at 100 with a completed_at stamp.
     */
    protected static function booted(): void
    {
        static::saving(function (ServiceSubTask $subTask) {
            if ($subTask->status === self::STATUS_COMPLETED) {
                $subTask->progress_percentage = 100;
                $subTask->completed_at = $subTask->completed_at ?? now();
            }
        });
    }

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
