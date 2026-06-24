<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'payment_id',
        'payment_request_id',
        'service_request_id',
        'user_id',
        'amount',
        'status',
        'payment_method',
        'mpesa_transaction_id',
        'mpesa_receipt_number',
        'phone_number',
        'paybill_number',
        'account_reference',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';

    const METHOD_MPESA = 'mpesa';
    const METHOD_CHEQUE = 'cheque';
    const METHOD_CASH = 'cash';
    const METHOD_BANK_DEPOSIT = 'bank_deposit';

    /**
     * Generate a unique payment ID.
     */
    public static function generatePaymentId(): string
    {
        $prefix = 'PMT';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}-{$date}-{$random}";
    }

    /**
     * Create a completed Payment record while guarding against duplicates.
     *
     * Two writes can race when the same M-Pesa transaction is reported by
     * more than one channel (callback + status poll, callback + C2B, manual
     * admin confirm + late callback). This helper checks for an existing
     * COMPLETED payment for the same PaymentRequest or with the same M-Pesa
     * receipt number before inserting — returning the existing row instead
     * of creating a duplicate.
     */
    public static function recordCompleted(array $attributes): self
    {
        $paymentRequestId   = $attributes['payment_request_id'] ?? null;
        $mpesaReceiptNumber = $attributes['mpesa_receipt_number'] ?? null;
        $mpesaTransactionId = $attributes['mpesa_transaction_id'] ?? null;

        // Wrap in a transaction so the lookup + insert happen atomically.
        // The PaymentRequest row lock prevents a sibling request (callback +
        // status poll firing within milliseconds of each other) from passing
        // the existence check before either has committed.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($attributes, $paymentRequestId, $mpesaReceiptNumber, $mpesaTransactionId) {
            // Lock the PaymentRequest row — siblings will wait until we
            // commit (or roll back), then re-evaluate.
            if ($paymentRequestId) {
                PaymentRequest::where('id', $paymentRequestId)->lockForUpdate()->first();
            }

            $existing = static::query()
                ->where('status', self::STATUS_COMPLETED)
                ->where(function ($q) use ($paymentRequestId, $mpesaReceiptNumber, $mpesaTransactionId) {
                    if ($paymentRequestId) {
                        $q->orWhere('payment_request_id', $paymentRequestId);
                    }
                    if ($mpesaReceiptNumber) {
                        $q->orWhere('mpesa_receipt_number', $mpesaReceiptNumber);
                    }
                    if ($mpesaTransactionId) {
                        $q->orWhere('mpesa_transaction_id', $mpesaTransactionId);
                    }
                })
                ->lockForUpdate()
                ->first();

            if ($existing) {
                \Illuminate\Support\Facades\Log::info('Payment::recordCompleted skipped duplicate', [
                    'existing_payment_id' => $existing->payment_id,
                    'attempted_attrs'     => array_intersect_key($attributes, array_flip([
                        'payment_request_id', 'mpesa_receipt_number', 'mpesa_transaction_id', 'amount',
                    ])),
                ]);
                return $existing;
            }

            $attributes['payment_id'] = $attributes['payment_id'] ?? self::generatePaymentId();
            $attributes['status']     = self::STATUS_COMPLETED;

            try {
                return static::create($attributes);
            } catch (\Illuminate\Database\QueryException $e) {
                // Last-line defense — DB unique constraint (mpesa_receipt_number, status)
                // caught the race we somehow lost. Re-fetch and return the winner.
                if ($e->getCode() === '23000' && $mpesaReceiptNumber) {
                    $winner = static::where('mpesa_receipt_number', $mpesaReceiptNumber)
                        ->where('status', self::STATUS_COMPLETED)
                        ->first();
                    if ($winner) {
                        \Illuminate\Support\Facades\Log::warning('Payment::recordCompleted hit DB unique constraint — returning winner', [
                            'winner_payment_id' => $winner->payment_id,
                            'receipt'           => $mpesaReceiptNumber,
                        ]);
                        return $winner;
                    }
                }
                throw $e;
            }
        });
    }

    /**
     * Get the payment request associated with this payment.
     */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /**
     * Get the service request associated with this payment.
     */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /**
     * Get the user who made the payment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if payment is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Mark payment as completed.
     */
    public function markAsCompleted(array $details = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'paid_at' => now(),
            ...$details,
        ]);
    }

    /**
     * Mark payment as failed.
     */
    public function markAsFailed(string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'notes' => $reason,
        ]);
    }
}
