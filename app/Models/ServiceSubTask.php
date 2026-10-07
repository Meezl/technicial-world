<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceSubTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_request_id',
        'variation_order_id',
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
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
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

    /**
     * The variation that bought this work, if it was not in the original
     * quotation. Null for original scope, which is most tasks.
     */
    public function variationOrder()
    {
        return $this->belongsTo(VariationOrder::class);
    }

    /** The admin who admitted this task to the job. */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Part of the quotation the client already agreed to. */
    public function scopeOriginalScope($query)
    {
        return $query->whereNull('variation_order_id');
    }

    /** Bought by a variation, whether or not that variation has been settled. */
    public function scopeUnderVariation($query)
    {
        return $query->whereNotNull('variation_order_id');
    }

    public function isVariationTask(): bool
    {
        return $this->variation_order_id !== null;
    }

    /** An admin has let this task onto the job. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * May this task be staffed, reported on and paid?
     *
     * Two separate consents, and both are needed. The variation answers
     * whether the work is bought — by the client for a priced variation, by
     * the office for a zero-income one. The task approval answers whether this
     * particular piece of work is admitted to the job, and only an admin may
     * give it.
     *
     * They can disagree in both directions: an approved variation whose task
     * nobody has signed off, and an approved task on a variation the client
     * then declined. This is the one question anything else should ask, rather
     * than reading the two stamps and combining them itself.
     *
     * Original scope is live as it always was: the quotation is its authority
     * and there is no second consent to wait for.
     */
    public function isLive(): bool
    {
        if (!$this->isVariationTask()) {
            return true;
        }

        return $this->isApproved() && (bool) $this->variationOrder?->isApproved();
    }

    /**
     * Why this task is not live, in words the office can act on. Null when it
     * is live.
     */
    public function blockedReason(): ?string
    {
        if ($this->isLive()) {
            return null;
        }

        $variation = $this->variationOrder;

        if (!$variation?->isApproved()) {
            $number = $variation?->vo_number ?? 'the variation';

            return $variation && $variation->isZeroIncome()
                ? "{$number} has not been approved internally yet."
                : "{$number} is still waiting on the client's approval.";
        }

        return 'An admin has not approved this task yet.';
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
