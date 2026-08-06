<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document that belongs to a job — a case analysis, a sample report, a
 * signed approval — held for the life of the REQ rather than living in email.
 *
 * Visibility is internal unless somebody deliberately says otherwise.
 */
class ServiceRequestDocument extends Model
{
    protected $fillable = [
        'service_request_id', 'ticket_id',
        'kind', 'title', 'notes',
        'path', 'original_name', 'mime', 'size_bytes',
        'is_client_visible', 'uploaded_by',
    ];

    protected $casts = [
        'is_client_visible' => 'boolean',
        'size_bytes'        => 'integer',
    ];

    /**
     * Internal unless someone deliberately says otherwise. Set on the model
     * rather than left to the column default, so a newly created document
     * reports false immediately instead of null — anything reading visibility
     * before a reload would otherwise be reasoning about a null.
     */
    protected $attributes = [
        'kind'              => self::KIND_OTHER,
        'is_client_visible' => false,
    ];

    const KIND_CASE_ANALYSIS = 'case_analysis';
    const KIND_SAMPLE_REPORT = 'sample_report';
    const KIND_PHOTO         = 'photo';
    const KIND_QUOTE_SUPPORT = 'quote_support';
    const KIND_APPROVAL      = 'approval';
    // A drawing, sketch or document the client sent in — in construction these
    // are often the actual brief, not an afterthought. Kept distinct from the
    // documents ops produce so who sent a file is legible at a glance.
    const KIND_CLIENT_UPLOAD = 'client_upload';
    const KIND_OTHER         = 'other';

    const KINDS = [
        self::KIND_CASE_ANALYSIS,
        self::KIND_SAMPLE_REPORT,
        self::KIND_PHOTO,
        self::KIND_QUOTE_SUPPORT,
        self::KIND_APPROVAL,
        self::KIND_CLIENT_UPLOAD,
        self::KIND_OTHER,
    ];

    public function isClientUpload(): bool
    {
        return $this->kind === self::KIND_CLIENT_UPLOAD;
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeClientVisible($query)
    {
        return $query->where('is_client_visible', true);
    }
}
