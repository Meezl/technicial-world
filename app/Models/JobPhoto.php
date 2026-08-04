<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Visual evidence attached to a job — a technician's progress shot, a
 * client's photo of a snag, a site attendance record from a ticket.
 *
 * See the create_job_photos_table migration for why this is separate from
 * ServiceRequestDocument.
 */
class JobPhoto extends Model
{
    protected $fillable = [
        'photoable_type',
        'photoable_id',
        'service_request_id',
        'file_path',
        'caption',
        'original_filename',
        'mime_type',
        'size_bytes',
        'added_by',
        'uploader_role',
        'removed_by_pm',
        'client_visible',
        'taken_at',
    ];

    protected $casts = [
        'removed_by_pm'  => 'boolean',
        'client_visible' => 'boolean',
        'size_bytes'     => 'integer',
        'taken_at'       => 'datetime',
    ];

    // The gallery needs a URL for every photo; making it an appended
    // attribute means no page has to know how storage paths are served.
    protected $appends = ['url'];

    public function photoable(): MorphTo
    {
        return $this->morphTo();
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function getUrlAttribute(): string
    {
        return '/storage/' . ltrim($this->file_path, '/');
    }

    /** Photos a PM has not excluded from approval. */
    public function scopeActive($query)
    {
        return $query->where('removed_by_pm', false);
    }

    /** Photos the client is allowed to see. */
    public function scopeClientVisible($query)
    {
        return $query->where('client_visible', true)->where('removed_by_pm', false);
    }
}
