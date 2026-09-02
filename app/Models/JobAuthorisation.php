<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One decision to run a job ahead of the client's money.
 *
 * See the create_job_authorisations_table migration for why this is a table
 * rather than a flag on the service request.
 */
class JobAuthorisation extends Model
{
    protected $fillable = [
        'service_request_id',
        'type',
        'reason',
        'authorised_by',
        'authorised_at',
        'expires_at',
        'exposure_cap',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'authorised_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'exposure_cap' => 'decimal:2',
    ];

    /**
     * Staff and start a job before the client has approved the quotation.
     *
     * Covers commencement as well: once the office has decided to carry a job
     * the client has not agreed to at all, withholding the crew over a deposit
     * that client has not yet been asked for achieves nothing.
     */
    const TYPE_PRE_APPROVAL = 'pre_approval';

    /** Start work on an approved job before its deposit has settled. */
    const TYPE_PRE_DEPOSIT = 'pre_deposit';

    const TYPES = [
        self::TYPE_PRE_APPROVAL => 'Pre-approval',
        self::TYPE_PRE_DEPOSIT => 'Pre-deposit',
    ];

    /** What each type actually permits, for the authorisation form. */
    const TYPE_DESCRIPTIONS = [
        self::TYPE_PRE_APPROVAL => 'Staff and start this job before the client has approved the quotation. Covers the deposit too.',
        self::TYPE_PRE_DEPOSIT => 'Start work on this approved job before the deposit has been received.',
    ];

    /** Does this authorisation permit work to start, not merely be staffed? */
    public function coversCommencement(): bool
    {
        return in_array($this->type, [self::TYPE_PRE_APPROVAL, self::TYPE_PRE_DEPOSIT], true);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function authoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Still carrying weight: not withdrawn and not lapsed.
     *
     * Expiry is read at the moment of asking rather than swept by a job, so an
     * authorisation stops working the instant it runs out even if nothing has
     * run since.
     */
    public function isLive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function hasLapsed(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function label(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Live authorisations, in the same terms isLive() uses. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
