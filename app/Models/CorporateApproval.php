<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a management company's sign-off on a quotation.
 *
 * See the create_corporate_approvals_table migration for why the chain is
 * materialised up front and tied to a revision.
 */
class CorporateApproval extends Model
{
    protected $fillable = [
        'service_request_id', 'quote_revision', 'stage', 'sequence', 'status',
        'decided_by', 'decided_by_member_id', 'comments',
        'lpo_number', 'lpo_document_path', 'payer_kra_pin',
        'signatory_name', 'signature_path', 'decided_at',
    ];

    protected $casts = [
        'quote_revision' => 'integer',
        'sequence' => 'integer',
        'decided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'quote_revision' => 0,
    ];

    /** First sign-off, used only by a two-stage company. */
    const STAGE_VERIFY = 'verify';

    /** The decision that turns a quotation into a job. */
    const STAGE_APPROVE = 'approve';

    const STAGE_LABELS = [
        self::STAGE_VERIFY => 'Verification',
        self::STAGE_APPROVE => 'Approval',
    ];

    /** Which membership position may act on each stage. */
    const STAGE_POSITIONS = [
        self::STAGE_VERIFY => OrganisationMember::POSITION_VERIFIER,
        self::STAGE_APPROVE => OrganisationMember::POSITION_APPROVER,
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_DECLINED = 'declined';

    /**
     * The quote moved on before this step was reached.
     *
     * Superseded rows are kept, not deleted. The brief asks for declined and
     * revised paperwork to stay accessible for the paper trail, and a chain
     * that quietly vanished when the figures changed would lose exactly the
     * history somebody later wants to read.
     */
    const STATUS_SUPERSEDED = 'superseded';

    /** Statuses that mean this chain is no longer live. */
    const CLOSED_STATUSES = [self::STATUS_DECLINED, self::STATUS_SUPERSEDED];

    // ==================== RELATIONSHIPS ====================

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganisationMember::class, 'decided_by_member_id');
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** The live chain — the one attached to the figures currently on offer. */
    public function scopeForRevision($query, int $revision)
    {
        return $query->where('quote_revision', $revision);
    }

    // ==================== HELPERS ====================

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFinalStage(): bool
    {
        return $this->stage === self::STAGE_APPROVE;
    }

    /** The membership position entitled to decide this step. */
    public function requiredPosition(): string
    {
        return self::STAGE_POSITIONS[$this->stage] ?? OrganisationMember::POSITION_APPROVER;
    }

    public function stageLabel(): string
    {
        return self::STAGE_LABELS[$this->stage] ?? ucfirst((string) $this->stage);
    }
}
