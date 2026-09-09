<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money that has arrived, and what it answers.
 *
 * See the create_settlements_tables migration for why one payment covers many
 * invoices, and why nothing counts until an accountant validates it.
 */
class Settlement extends Model
{
    protected $fillable = [
        'client_organisation_id', 'method', 'gross_amount', 'reference', 'paid_on',
        'proof_path', 'statement_path', 'status',
        'submitted_by', 'validated_by', 'validated_at', 'rejection_reason', 'note',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'paid_on' => 'date',
        'validated_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_SUBMITTED];

    const METHOD_RTGS = 'rtgs';
    const METHOD_CHEQUE = 'cheque';
    const METHOD_MPESA = 'mpesa';
    const METHOD_OTHER = 'other';

    const METHODS = [
        self::METHOD_RTGS => 'Bank transfer / RTGS',
        self::METHOD_CHEQUE => 'Cheque',
        self::METHOD_MPESA => 'M-Pesa',
        self::METHOD_OTHER => 'Other',
    ];

    const STATUS_SUBMITTED = 'submitted';
    const STATUS_VALIDATED = 'validated';
    const STATUS_REJECTED = 'rejected';

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SettlementAllocation::class);
    }

    public function invoices()
    {
        return $this->belongsToMany(Invoice::class, 'settlement_allocations')->withPivot('amount');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function scopeAwaitingValidation($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    /** What the client says this payment covers, added up. */
    public function allocatedTotal(): float
    {
        return (float) $this->allocations()->sum('amount');
    }
}
