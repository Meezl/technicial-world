<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person's standing inside one management company.
 *
 * See the create_organisation_members_table migration for why position is not
 * a platform role and why a user belongs to only one organisation.
 */
class OrganisationMember extends Model
{
    protected $fillable = [
        'client_organisation_id', 'user_id', 'position',
        'display_name', 'signature_path', 'can_approve_up_to', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'can_approve_up_to' => 'decimal:2',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $appends = ['name_on_documents'];

    /** Raises requests. The caretaker who deals with the tenants. */
    const POSITION_REQUESTER = 'requester';

    /** First sign-off, in a two-stage workflow only. */
    const POSITION_VERIFIER = 'verifier';

    /** The senior manager whose approval turns a quote into a job. */
    const POSITION_APPROVER = 'approver';

    /** Handles payment proofs and withholding-tax certificates. */
    const POSITION_ACCOUNTS = 'accounts';

    const POSITIONS = [
        self::POSITION_REQUESTER => 'Requester',
        self::POSITION_VERIFIER => 'Verifier',
        self::POSITION_APPROVER => 'Approver',
        self::POSITION_ACCOUNTS => 'Accounts',
    ];

    /**
     * Positions that decide on a quotation.
     *
     * Used to insist that a company has somebody who can actually say yes
     * before its people start raising requests nobody can approve.
     */
    const DECIDING_POSITIONS = [self::POSITION_VERIFIER, self::POSITION_APPROVER];

    // ==================== RELATIONSHIPS ====================

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Requests this person raised. */
    public function raisedRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'raised_by_member_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInPosition($query, string|array $position)
    {
        return $query->whereIn('position', (array) $position);
    }

    // ==================== HELPERS ====================

    public function isRequester(): bool
    {
        return $this->position === self::POSITION_REQUESTER;
    }

    public function isApprover(): bool
    {
        return $this->position === self::POSITION_APPROVER;
    }

    public function isVerifier(): bool
    {
        return $this->position === self::POSITION_VERIFIER;
    }

    /**
     * What goes on an invoice or approval block.
     *
     * The company's own shorthand for the person where they have given us one,
     * their account name otherwise. Never blank — an invoice line that cannot
     * say who asked for the work fails the brief.
     */
    public function getNameOnDocumentsAttribute(): string
    {
        return $this->display_name ?: (string) ($this->user?->name ?? '');
    }

    /**
     * Can this person approve an amount this large?
     *
     * A null ceiling means no ceiling. Anyone who is not an approver cannot
     * approve at all, whatever their ceiling says.
     */
    public function canApprove(float $amount): bool
    {
        if (!$this->is_active || !in_array($this->position, self::DECIDING_POSITIONS, true)) {
            return false;
        }

        return $this->can_approve_up_to === null
            || (float) $this->can_approve_up_to >= $amount;
    }
}
