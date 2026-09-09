<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The terms of a management company's standing float.
 *
 * See the create_deposit_accounts_table migration for why this holds terms
 * and not money.
 */
class DepositAccount extends Model
{
    protected $fillable = [
        'client_organisation_id', 'ceiling_amount',
        'threshold_type', 'threshold_value', 'currency',
        'override_threshold_value', 'override_reason', 'override_by',
        'override_at', 'override_expires_at',
        'opened_on', 'is_active',
    ];

    protected $casts = [
        'ceiling_amount' => 'decimal:2',
        'threshold_value' => 'decimal:2',
        'override_threshold_value' => 'decimal:2',
        'override_at' => 'datetime',
        'override_expires_at' => 'datetime',
        'opened_on' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'threshold_type' => self::THRESHOLD_ABSOLUTE,
        'currency' => 'KES',
        'is_active' => true,
    ];

    /** A figure in shillings: "invoice when it falls to 300,000". */
    const THRESHOLD_ABSOLUTE = 'absolute';

    /** A share of the ceiling: "invoice when it reduces to 50%". */
    const THRESHOLD_PERCENT = 'percent';

    const THRESHOLD_TYPES = [
        self::THRESHOLD_ABSOLUTE => 'Fixed amount',
        self::THRESHOLD_PERCENT => 'Percentage of the float',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DepositLedgerEntry::class)->orderBy('occurred_on')->orderBy('id');
    }

    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    /**
     * The figure the float must not fall below, in shillings.
     *
     * An admin override replaces it while it is live. Expiry is checked here
     * rather than swept by a job: an override that has run out must stop
     * applying the moment it does, and a sweep that has not run yet would
     * leave it quietly in force.
     */
    public function effectiveThreshold(): float
    {
        if ($this->hasLiveOverride()) {
            return (float) $this->override_threshold_value;
        }

        return $this->baseThreshold();
    }

    /** The agreed threshold, ignoring any override. */
    public function baseThreshold(): float
    {
        if ($this->threshold_type === self::THRESHOLD_PERCENT) {
            return round((float) $this->ceiling_amount * ((float) $this->threshold_value / 100), 2);
        }

        return (float) $this->threshold_value;
    }

    public function hasLiveOverride(): bool
    {
        return $this->override_threshold_value !== null
            && ($this->override_expires_at === null || $this->override_expires_at->isFuture());
    }
}
