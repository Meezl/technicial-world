<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One closed job's bill.
 *
 * Held in the in-tray until the float drops through its threshold, then
 * dispatched as a proforma with the rest. See the create_invoices_table
 * migration for why the tax figures are stored rather than derived.
 */
class Invoice extends Model
{
    protected $fillable = [
        'invoice_number', 'client_organisation_id', 'service_request_id', 'invoice_batch_id',
        'status', 'kind',
        'subtotal_ex_vat', 'vat_rate', 'vat_amount', 'total_inc_vat',
        'whvat_rate', 'whvat_amount', 'wht_rate', 'wht_amount', 'net_expected',
        'paid_amount', 'certified_amount',
        'property_name', 'requester_name', 'approver_name',
        'lpo_number', 'payer_kra_pin', 'job_completed_on',
        'etims_receipt_path', 'etims_receipt_number', 'void_reason',
        'issued_at', 'dispatched_at', 'settled_at',
    ];

    protected $casts = [
        'subtotal_ex_vat' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_inc_vat' => 'decimal:2',
        'whvat_rate' => 'decimal:2',
        'whvat_amount' => 'decimal:2',
        'wht_rate' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'net_expected' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'certified_amount' => 'decimal:2',
        'job_completed_on' => 'date',
        'issued_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_HELD,
        'kind' => self::KIND_PROFORMA,
    ];

    /** In the in-tray. Raised, but the client has not seen it. */
    const STATUS_HELD = 'held';
    const STATUS_DISPATCHED = 'dispatched';
    const STATUS_PART_SETTLED = 'part_settled';
    const STATUS_SETTLED = 'settled';
    const STATUS_VOID = 'void';

    const KIND_PROFORMA = 'proforma';
    const KIND_TAX_INVOICE = 'tax_invoice';

    /** Statuses that still owe us something. */
    const OPEN_STATUSES = [self::STATUS_DISPATCHED, self::STATUS_PART_SETTLED];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InvoiceBatch::class, 'invoice_batch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SettlementAllocation::class);
    }

    public function taxCertificates(): HasMany
    {
        return $this->hasMany(TaxCertificate::class);
    }

    public function scopeHeld($query)
    {
        return $query->where('status', self::STATUS_HELD);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * Everything still outstanding, cash and certificates together.
     *
     * Withholding tax is money we are owed and will get; it just arrives as a
     * certificate rather than as cash. Treating it as a shortfall would leave
     * every invoice looking 5% underpaid for ever.
     */
    public function outstanding(): float
    {
        return round(
            (float) $this->total_inc_vat - (float) $this->paid_amount - (float) $this->certified_amount,
            2
        );
    }

    /** Has the cash we expected — gross less withholding — actually landed? */
    public function cashSettled(): bool
    {
        return (float) $this->paid_amount >= (float) $this->net_expected - 0.01;
    }

    public function fullySettled(): bool
    {
        return $this->outstanding() <= 0.01;
    }
}
