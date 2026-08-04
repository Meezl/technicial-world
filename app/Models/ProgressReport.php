<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProgressReport extends Model
{
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
    ];

    protected $casts = [
        'report_date' => 'date',
        'is_validated' => 'boolean',
        'validated_at' => 'datetime',
        'is_pm_authored' => 'boolean',
        'percent_complete' => 'integer',
        'validated_percent' => 'integer',
    ];

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
