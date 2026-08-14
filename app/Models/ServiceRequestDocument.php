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
    // A spec or drawing ops produce for the job — the technical intent of the
    // work, meant to reach the technician on site. Distinct from the client's
    // own brief and from the office's commercial paperwork.
    const KIND_SPEC          = 'spec';
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
        self::KIND_SPEC,
        self::KIND_CLIENT_UPLOAD,
        self::KIND_OTHER,
    ];

    /**
     * Human-readable labels for the upload UI, in the order they should list.
     */
    const KIND_LABELS = [
        self::KIND_CLIENT_UPLOAD => 'Client upload',
        self::KIND_SPEC          => 'Spec / drawing',
        self::KIND_CASE_ANALYSIS => 'Case analysis',
        self::KIND_QUOTE_SUPPORT => 'Quote support',
        self::KIND_SAMPLE_REPORT => 'Sample report',
        self::KIND_APPROVAL      => 'Approval',
        self::KIND_PHOTO         => 'Photo',
        self::KIND_OTHER         => 'Other',
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

    /**
     * Document kinds a technician working the job may see: the client's own
     * briefs and drawings, and the specs/drawings ops draw for the job.
     * Everything the office authored as commercial paperwork for the client —
     * quotations, quote support, signed approvals, sample reports — and its
     * internal margin thinking (case analyses) stays out, even once shared
     * with the client. General "other" documents are not shipped wholesale:
     * an ops file meant for the technician is filed as a spec.
     */
    const TECHNICIAN_VISIBLE_KINDS = [
        self::KIND_CLIENT_UPLOAD,
        self::KIND_SPEC,
    ];

    /**
     * Files a technician working the job may see: the client's briefs and the
     * specs ops draw for the job, but never the office's commercial paperwork
     * for the client. A document must be shared with the client too — internal
     * files stay internal. Anything ops means for one specific technician
     * still travels through their job assignment (see
     * TechnicianController::assignmentFilesFor).
     */
    public function scopeTechnicianVisible($query)
    {
        return $query->clientVisible()->whereIn('kind', self::TECHNICIAN_VISIBLE_KINDS);
    }
}
