<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentRequest extends Model
{
    protected $fillable = [
        'payment_request_id',
        'service_request_id',
        'ticket_id',
        'variation_order_id',
        'user_id',
        'requested_by',
        'percentage',
        'is_deposit',
        'amount',
        'status',
        'payment_method',
        'mpesa_checkout_request_id',
        'mpesa_transaction_id',
        'mpesa_receipt_number',
        'phone_number',
        'cheque_number',
        'bank_reference',
        'notes',
        'evidence_path',
        'paid_at',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'is_deposit' => 'boolean',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The client is reminded every 12 hours until it is paid or withdrawn.
        static::created(function (self $paymentRequest) {
            if ($paymentRequest->status === self::STATUS_PENDING) {
                ActionReminder::openFor($paymentRequest, ActionReminder::KIND_PAYMENT);
            }
        });

        static::updated(function (self $paymentRequest) {
            if ($paymentRequest->wasChanged('status') && $paymentRequest->status !== self::STATUS_PENDING) {
                ActionReminder::closeFor($paymentRequest, ActionReminder::KIND_PAYMENT);
            }
        });
    }

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    const METHOD_MPESA = 'mpesa';
    const METHOD_CHEQUE = 'cheque';
    const METHOD_CASH = 'cash';
    const METHOD_BANK_DEPOSIT = 'bank_deposit';

    /** Paid outside M-Pesa: the client records it, then the office confirms. */
    const OFFLINE_METHODS = [self::METHOD_CHEQUE, self::METHOD_CASH, self::METHOD_BANK_DEPOSIT];

    /**
     * Generate a unique payment request ID.
     */
    public static function generatePaymentRequestId(): string
    {
        $prefix = 'PAY';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}-{$date}-{$random}";
    }

    /**
     * Get the service request associated with this payment request.
     */
    /**
     * Set when this request bills a ticket attendance fee rather than quoted
     * work. Attendance sits outside the contract cap — see BillingService.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** The variation this bills for, when it is not the original quote. */
    public function variationOrder(): BelongsTo
    {
        return $this->belongsTo(VariationOrder::class);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /**
     * Get the user (client) who should make the payment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who requested the payment.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the payment record if payment was completed.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * The bill raised off the quotation's deposit line.
     *
     * A job has at most one live deposit request: a revision cancels the
     * outstanding one and the new quotation raises a fresh one, so the scope
     * is filtered further by status wherever "the current deposit" is meant.
     */
    public function scopeDeposit(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_deposit', true);
    }

    /**
     * Check if payment request is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if payment request is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Mark payment as completed.
     */
    public function markAsPaid(string $method, array $details = []): void
    {
        // Idempotent — if the request is already paid, do nothing. This
        // prevents a duplicate callback from overwriting the original
        // paid_at timestamp or re-triggering downstream listeners.
        if ($this->status === self::STATUS_PAID) {
            return;
        }

        $this->update([
            'status' => self::STATUS_PAID,
            'payment_method' => $method,
            'paid_at' => now(),
            ...$details,
        ]);
    }
}