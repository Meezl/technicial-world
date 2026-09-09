<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof that the withheld portion of an invoice reached KRA.
 *
 * See the create_tax_certificates_table migration for why a job stays alive
 * until these arrive.
 */
class TaxCertificate extends Model
{
    protected $fillable = [
        'client_organisation_id', 'invoice_id', 'type', 'certificate_number',
        'amount', 'certificate_date', 'document_path', 'status',
        'submitted_by', 'validated_by', 'validated_at', 'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'certificate_date' => 'date',
        'validated_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_SUBMITTED];

    /** Withholding tax — 3% of the VAT-exclusive value. */
    const TYPE_WHT = 'wht';

    /** Withholding VAT — 2% of the VAT-exclusive value. */
    const TYPE_WHVAT = 'whvat';

    const TYPES = [
        self::TYPE_WHT => 'Withholding tax (WHT)',
        self::TYPE_WHVAT => 'Withholding VAT (WHVAT)',
    ];

    const STATUS_SUBMITTED = 'submitted';
    const STATUS_VALIDATED = 'validated';
    const STATUS_REJECTED = 'rejected';

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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

    public function label(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
