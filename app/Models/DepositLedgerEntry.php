<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a float.
 *
 * Append-only by intent: nothing in the application updates or deletes an
 * entry. A mistake is corrected with an `adjustment` carrying a reason, the
 * same way an approved variation is corrected with an offsetting variation
 * rather than an edit. That is what keeps the ledger defensible.
 */
class DepositLedgerEntry extends Model
{
    protected $fillable = [
        'deposit_account_id', 'entry_type', 'amount',
        'balance_after', 'committed_after',
        'service_request_id', 'reference', 'note', 'recorded_by', 'occurred_on',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'committed_after' => 'decimal:2',
        'occurred_on' => 'date',
    ];

    const TYPE_BOOKING = 'booking';
    const TYPE_COMMITMENT = 'commitment';
    const TYPE_COMMITMENT_RELEASE = 'commitment_release';
    const TYPE_CONSUMPTION = 'consumption';
    const TYPE_SETTLEMENT_TOPUP = 'settlement_topup';
    const TYPE_TAX_CERTIFICATE_TOPUP = 'tax_certificate_topup';
    const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Types that move actual money.
     *
     * A commitment is not cash — the client's float is still worth what it
     * was; it is simply spoken for. Summing the two dimensions together would
     * report a float that had already shrunk for work nobody has done yet.
     */
    const CASH_TYPES = [
        self::TYPE_BOOKING,
        self::TYPE_CONSUMPTION,
        self::TYPE_SETTLEMENT_TOPUP,
        self::TYPE_TAX_CERTIFICATE_TOPUP,
        self::TYPE_ADJUSTMENT,
    ];

    /** Types that encumber the float without spending it. */
    const COMMITMENT_TYPES = [
        self::TYPE_COMMITMENT,
        self::TYPE_COMMITMENT_RELEASE,
    ];

    const TYPE_LABELS = [
        self::TYPE_BOOKING => 'Deposit received',
        self::TYPE_COMMITMENT => 'Committed to an approved job',
        self::TYPE_COMMITMENT_RELEASE => 'Commitment released',
        self::TYPE_CONSUMPTION => 'Job closed',
        self::TYPE_SETTLEMENT_TOPUP => 'Payment received',
        self::TYPE_TAX_CERTIFICATE_TOPUP => 'Tax certificates validated',
        self::TYPE_ADJUSTMENT => 'Adjustment',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'deposit_account_id');
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isCash(): bool
    {
        return in_array($this->entry_type, self::CASH_TYPES, true);
    }

    public function label(): string
    {
        return self::TYPE_LABELS[$this->entry_type] ?? $this->entry_type;
    }
}
