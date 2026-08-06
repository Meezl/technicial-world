<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money owed back to a client, and whether it has actually gone.
 *
 * A credit note is the same record with a method that does not move money —
 * the client is owed it against this job instead. Keeping both here means an
 * amount owed is never invisible just because nobody has transferred it yet.
 */
class Refund extends Model
{
    protected $fillable = [
        'refund_ref', 'service_request_id',
        'ticket_id', 'variation_order_id', 'payment_id',
        'amount', 'category', 'reason', 'method', 'status',
        'requested_by', 'approved_by', 'approved_at',
        'settled_at', 'settlement_reference', 'rejection_reason',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'approved_at' => 'datetime',
        'settled_at'  => 'datetime',
    ];

    /** Column defaults are not applied to the in-memory model. */
    protected $attributes = [
        'category' => self::CATEGORY_OTHER,
        'method'   => self::METHOD_CREDIT_NOTE,
        'status'   => self::STATUS_PENDING_APPROVAL,
    ];

    const CATEGORY_OVERPAYMENT          = 'overpayment';
    const CATEGORY_CANCELLED_ATTENDANCE = 'cancelled_attendance';
    const CATEGORY_WAIVED_AFTER_PAYMENT = 'waived_after_payment';
    const CATEGORY_SCOPE_REDUCTION      = 'scope_reduction';
    const CATEGORY_OTHER                = 'other';

    const CATEGORIES = [
        self::CATEGORY_OVERPAYMENT,
        self::CATEGORY_CANCELLED_ATTENDANCE,
        self::CATEGORY_WAIVED_AFTER_PAYMENT,
        self::CATEGORY_SCOPE_REDUCTION,
        self::CATEGORY_OTHER,
    ];

    const METHOD_MPESA       = 'mpesa';
    const METHOD_BANK        = 'bank';
    const METHOD_CASH        = 'cash';
    /** Owed against this job rather than paid out. */
    const METHOD_CREDIT_NOTE = 'credit_note';

    const METHODS = [
        self::METHOD_MPESA,
        self::METHOD_BANK,
        self::METHOD_CASH,
        self::METHOD_CREDIT_NOTE,
    ];

    const STATUS_PENDING_APPROVAL = 'pending_approval';
    const STATUS_APPROVED         = 'approved';
    const STATUS_SETTLED          = 'settled';
    const STATUS_REJECTED         = 'rejected';

    /**
     * Statuses that reduce what the client is treated as having paid.
     *
     * An approved refund counts immediately, before the money physically
     * moves. The alternative — waiting for settlement — would leave the job
     * showing the client as fully paid while we owe them money, and that is
     * the state that produces the next angry thread.
     */
    const REDUCES_SETTLED = [self::STATUS_APPROVED, self::STATUS_SETTLED];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function variationOrder(): BelongsTo
    {
        return $this->belongsTo(VariationOrder::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isCreditNote(): bool
    {
        return $this->method === self::METHOD_CREDIT_NOTE;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED], true);
    }

    /** Approved but the money has not physically gone yet. */
    public function isAwaitingSettlement(): bool
    {
        return $this->status === self::STATUS_APPROVED && !$this->isCreditNote();
    }

    public static function generateRef(): string
    {
        do {
            $ref = 'RFD-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        } while (self::where('refund_ref', $ref)->exists());

        return $ref;
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::CATEGORY_OVERPAYMENT          => 'Overpayment',
            self::CATEGORY_CANCELLED_ATTENDANCE => 'Attendance cancelled',
            self::CATEGORY_WAIVED_AFTER_PAYMENT => 'Fee waived after payment',
            self::CATEGORY_SCOPE_REDUCTION      => 'Scope reduced',
            default                             => 'Other',
        };
    }
}
