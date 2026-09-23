<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A negotiated price list, held as a version.
 *
 * See the create_rate_schedules_table migration for why editing rates in place
 * would re-price history.
 */
class RateSchedule extends Model
{
    protected $fillable = [
        'client_organisation_id', 'name', 'version', 'status',
        'effective_from', 'notes', 'created_by', 'approved_by', 'activated_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'effective_from' => 'date',
        'activated_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT, 'version' => 1];

    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUPERSEDED = 'superseded';

    const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_SUPERSEDED => 'Superseded',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RateItem::class);
    }

    public function activeItems(): HasMany
    {
        return $this->items()->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** The house list, for clients with nothing negotiated. */
    public function isHouseList(): bool
    {
        return $this->client_organisation_id === null;
    }

    /**
     * The list a given client is quoted from.
     *
     * Their own negotiated schedule where they have one, the house list
     * otherwise — so a client without an SLA can still be auto-quoted rather
     * than falling back to typing everything by hand.
     */
    public static function forOrganisation(?ClientOrganisation $organisation): ?self
    {
        if ($organisation) {
            $own = static::active()->where('client_organisation_id', $organisation->id)->first();

            if ($own) {
                return $own;
            }
        }

        return static::active()->whereNull('client_organisation_id')->first();
    }
}
