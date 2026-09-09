<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The client's own request for additional scope.
 *
 * See the create_variation_cards_table migration for why this is separate from
 * the variation order that eventually prices it.
 */
class VariationCard extends Model
{
    protected $fillable = [
        'card_number', 'service_request_id', 'raised_by_member_id',
        'scope_description', 'justification', 'status',
        'decided_by', 'decided_by_member_id', 'decision_comments', 'decided_at',
        'variation_order_id',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    /** With the client's own approver. */
    const STATUS_PENDING = 'pending';

    /** Their side has agreed. Ours can price it. */
    const STATUS_APPROVED = 'approved';

    /** Turned down, with comments, and kept. */
    const STATUS_DECLINED = 'declined';

    /** A variation order has been raised from it. */
    const STATUS_QUOTED = 'quoted';

    const STATUS_LABELS = [
        self::STATUS_PENDING => 'Awaiting your manager',
        self::STATUS_APPROVED => 'Approved — with Technician World to price',
        self::STATUS_DECLINED => 'Declined',
        self::STATUS_QUOTED => 'Quoted',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function raisedByMember(): BelongsTo
    {
        return $this->belongsTo(OrganisationMember::class, 'raised_by_member_id');
    }

    public function decidedByMember(): BelongsTo
    {
        return $this->belongsTo(OrganisationMember::class, 'decided_by_member_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function variationOrder(): BelongsTo
    {
        return $this->belongsTo(VariationOrder::class);
    }

    /** Cards the office can act on: agreed by the client, not yet priced. */
    public function scopeReadyToQuote($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeAwaitingDecision($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** REQ-ABC123/VC-01 — sequential per job. */
    public static function nextNumberFor(ServiceRequest $sr): string
    {
        $used = static::where('service_request_id', $sr->id)->count();

        return sprintf('%s/VC-%02d', $sr->request_id, $used + 1);
    }
}
