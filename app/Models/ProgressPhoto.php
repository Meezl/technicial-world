<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressPhoto extends Model
{
    protected $fillable = [
        'progress_report_id',
        'file_path',
        'caption',
        'added_by',
        'removed_by_pm',
    ];

    protected $casts = [
        'removed_by_pm' => 'boolean',
    ];

    public function progressReport(): BelongsTo
    {
        return $this->belongsTo(ProgressReport::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
