<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One building, branch or station in a management company's portfolio.
 *
 * See the create_properties_table migration for why the landlord's PIN lives
 * here rather than on the request.
 */
class Property extends Model
{
    protected $fillable = [
        'client_organisation_id', 'name', 'code', 'address',
        'owner_name', 'owner_kra_pin', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $appends = ['label'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * How the property reads in a dropdown and on a document.
     *
     * One accessor so the invoice, the quotation and the picker cannot drift
     * into naming the same building three different ways.
     */
    public function getLabelAttribute(): string
    {
        return $this->code ? "{$this->name} ({$this->code})" : (string) $this->name;
    }
}
