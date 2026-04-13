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
        'user_id',
        'requested_by',
        'percentage',
        'amount',
        'status',
        'payment_method',
        'mpesa_checkout_request_id',
        'mpesa_transaction_id',
        'mpesa_receipt_number',
        'phone_number',
        'cheque_number',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    const METHOD_MPESA = 'mpesa';
    const METHOD_CHEQUE = 'cheque';
    const METHOD_CASH = 'cash';

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
        $this->update([
            'status' => self::STATUS_PAID,
            'payment_method' => $method,
            'paid_at' => now(),
            ...$details,
        ]);
    }
}