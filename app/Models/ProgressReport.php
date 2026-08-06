<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProgressReport extends Model
{
    /**
     * Removed reports are kept, not destroyed — job_photos still cascades
     * from this table and technician_payments points at it. See the
     * add_soft_deletes_to_progress_reports migration.
     */
    use SoftDeletes;

    protected $fillable = [
        'service_request_id',
        'service_sub_task_id',
        'technician_id',
        'submitted_by',
        'report_date',
        'percent_complete',
        'notes',
        'is_validated',
        'validated_by',
        'validated_at',
        'validated_percent',
        'validation_notes',
        'client_visible_notes',
        'is_pm_authored',
        'authored_as',
        'validated_as',
        'approved_by_lead_at',
        'rejected_at',
        'rejected_by',
        'rejected_as',
        'rejection_reason',
        'revised_by_lead_at',
        'deleted_by',
        'deletion_reason',
        'restored_at',
        'restored_by',
        'restore_reason',
        'lead_override_at',
        'lead_overridden_by',
        'lead_override_reason',
        'lead_approved_percent',
    ];

    /**
     * The standing someone had when they wrote or ratified a report. Distinct
     * from technician_id, which says whose work the report is about — the two
     * differ whenever one person files on another's behalf.
     */
    const AS_TECHNICIAN = 'technician';
    const AS_LEAD = 'lead';
    const AS_PROJECT_MANAGER = 'project_manager';
    const AS_ADMIN = 'admin';

    /** Capacities that mean "the office", as opposed to on site. */
    const OFFICE_CAPACITIES = [self::AS_PROJECT_MANAGER, self::AS_ADMIN];

    protected $casts = [
        'report_date' => 'date',
        'is_validated' => 'boolean',
        'validated_at' => 'datetime',
        'is_pm_authored' => 'boolean',
        'percent_complete' => 'integer',
        'validated_percent' => 'integer',
        'approved_by_lead_at' => 'datetime',
        'rejected_at' => 'datetime',
        'revised_by_lead_at' => 'datetime',
        'restored_at' => 'datetime',
        'lead_override_at' => 'datetime',
        'lead_approved_percent' => 'integer',
    ];

    /**
     * Still on the office's desk: never validated, or validated on site by a
     * lead — which moves progress but deliberately does not release billing,
     * so a PM still has to look at it. Reports a lead has sent back are not
     * here; they were resolved on site and are the technician's to redo.
     */
    public function scopeNeedsOfficeAction($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($unvalidated) {
                $unvalidated->where('is_validated', false)->whereNull('rejected_at');
            })->orWhereNotNull('approved_by_lead_at');
        });
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    /**
     * Sent back by the office, so it is the lead's to edit or comment on
     * before it goes back up. A lead's own rejection lands on the crew member
     * instead, who redoes the work and files afresh.
     */
    public function isReturnedToLead(): bool
    {
        return $this->rejected_at !== null
            && in_array($this->rejected_as, self::OFFICE_CAPACITIES, true);
    }

    /**
     * Waiting on the lead to revise it. Distinct from needsOfficeAction —
     * these are off the office's desk until the lead sends them back up.
     */
    public function scopeAwaitingLeadRevision($query)
    {
        return $query->whereNotNull('rejected_at')
            ->whereIn('rejected_as', self::OFFICE_CAPACITIES);
    }

    /**
     * True when the person whose work this is did not write it — a lead
     * covering for a crew member, or the office catching a job up. Worth
     * saying out loud on screen: a report about someone's work that they did
     * not write is a different kind of evidence.
     */
    public function isOnBehalf(): bool
    {
        return $this->authored_as !== null
            && $this->authored_as !== self::AS_TECHNICIAN;
    }

    /**
     * Map a user's role onto the capacity they act in. The lead capacity is
     * per-job rather than a role, so callers pass that one explicitly.
     */
    public static function capacityForRole(?string $role): string
    {
        return match ($role) {
            User::ROLE_ADMIN => self::AS_ADMIN,
            User::ROLE_PROJECT_MANAGER => self::AS_PROJECT_MANAGER,
            default => self::AS_TECHNICIAN,
        };
    }

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

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Photos now live in the shared job_photos table so a report's photos and
     * a client's evidence on the same job sit side by side. Column names are
     * unchanged from the old progress_photos table, so callers see the same
     * shape they always did.
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(JobPhoto::class, 'photoable');
    }

    public function activePhotos(): MorphMany
    {
        return $this->photos()->where('removed_by_pm', false);
    }

    /**
     * Append-only edit history for notes fields. Populated by
     * ProgressService::validate whenever a save changes client_visible_notes
     * or validation_notes. Ops-only — never exposed to the client portal.
     */
    public function noteVersions(): HasMany
    {
        return $this->hasMany(ProgressReportNoteVersion::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get the effective progress percentage (validated takes precedence).
     */
    public function getEffectivePercentAttribute(): int
    {
        if ($this->is_validated && $this->validated_percent !== null) {
            return $this->validated_percent;
        }
        return $this->percent_complete;
    }
}
