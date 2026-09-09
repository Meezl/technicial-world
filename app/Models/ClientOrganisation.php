<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A property management company.
 *
 * See the create_client_organisations_table migration for why the organisation
 * is the client of record rather than a `users` row.
 */
class ClientOrganisation extends Model
{
    protected $fillable = [
        'name', 'kra_pin', 'billing_email', 'phone', 'address', 'logo_path',
        'approval_workflow', 'vat_rate', 'whvat_rate', 'wht_rate',
        'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'vat_rate' => 'decimal:2',
        'whvat_rate' => 'decimal:2',
        'wht_rate' => 'decimal:2',
    ];

    /** Column defaults are not applied to an in-memory model. */
    protected $attributes = [
        'approval_workflow' => self::WORKFLOW_SINGLE_STAGE,
        'is_active' => true,
    ];

    /** Straight to the approver. */
    const WORKFLOW_SINGLE_STAGE = 'single_stage';

    /** Verifier first, then the approver. */
    const WORKFLOW_TWO_STAGE = 'two_stage';

    const WORKFLOWS = [
        self::WORKFLOW_SINGLE_STAGE => 'Single stage (approver only)',
        self::WORKFLOW_TWO_STAGE => 'Two stage (verifier, then approver)',
    ];

    // ==================== RELATIONSHIPS ====================

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class)->orderBy('sort_order')->orderBy('name');
    }

    public function activeProperties(): HasMany
    {
        return $this->properties()->where('is_active', true);
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganisationMember::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /**
     * Their standing float. One per company — see the deposit_accounts
     * migration for why two would leave "how much is left" unanswerable.
     */
    public function depositAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DepositAccount::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoiceBatches(): HasMany
    {
        return $this->hasMany(InvoiceBatch::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function taxCertificates(): HasMany
    {
        return $this->hasMany(TaxCertificate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ==================== HELPERS ====================

    public function requiresTwoStageApproval(): bool
    {
        return $this->approval_workflow === self::WORKFLOW_TWO_STAGE;
    }

    /**
     * The people who may sign off, in the order they are asked.
     *
     * A single-stage company has no verifier step even if somebody has been
     * given the position — the workflow setting is what decides, not who
     * happens to hold a title. Otherwise adding a verifier to a company that
     * does not use one would quietly lengthen their approval chain.
     */
    public function approvalChain(): array
    {
        return $this->requiresTwoStageApproval()
            ? [OrganisationMember::POSITION_VERIFIER, OrganisationMember::POSITION_APPROVER]
            : [OrganisationMember::POSITION_APPROVER];
    }
}
