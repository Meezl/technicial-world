<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressReportNoteVersion extends Model
{
    // Versions are append-only — no updates or user-facing timestamps.
    public $timestamps = false;

    protected $fillable = [
        'progress_report_id',
        'edited_by',
        'field_name',
        'previous_text',
        'new_text',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ProgressReport::class, 'progress_report_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
