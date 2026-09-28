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

            // "Assigned" to nobody is not a state this should be able to
            // reach, and it is what the board showed: a task badged Assigned
            // sitting above the word Unassigned. Whatever emptied the
            // technician — an older unassign path, a technician row removed —
            // the status has to follow, or the office reads a job as staffed
            // that nobody is on.
            if (!$subTask->technician_id && $subTask->status === self::STATUS_ASSIGNED) {
                $subTask->status = self::STATUS_PENDING;
            }

            // A task belongs to a tradesman.
            //
            // A gang member is on site under somebody else's scope, with a
            // description of what they will be doing and no work of their own
            // to report against or be paid for. Handing them a sub-task would
            // give them a percentage to move, a fee to draw and a place in the
            // payment sheet — three things they do not have. Enforced here
            // rather than only at the forms because a sub-task is written from
            // several places and they all pass through this.
            if ($subTask->technician_id) {
                $technician = Technician::find($subTask->technician_id);
                if ($technician?->isGangMember()) {
                    throw new \LogicException(
                        'A sub-task cannot be assigned to a gang member. They join the crew with a '
                        . 'description of what they will be doing on site, not with work of their own.'
                    );
                }
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
