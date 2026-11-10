<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    protected $fillable = [
        'payment_request_id',
        'service_request_id',
        'checkout_request_id',
        'merchant_request_id',
        'receipt_number',
        'amount',
        'allocated_amount',
        'phone_number',
        'result_code',
        'result_desc',
        'transaction_date',
        'status',
        'source',
        'bill_ref_number',
        'payer_name',
        'reconciled',
        'match_status',
        'allocations',
        'match_method',
        'match_note',
        'matched_by',
        'matched_at',
    ];

    protected $casts = [
        'reconciled'       => 'boolean',
        'amount'           => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'allocations'      => 'array',
        'matched_at'       => 'datetime',
    ];

    protected $appends = ['unapplied_amount'];

    const STATUS_INITIATED = 'initiated';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    const SOURCE_STK_PUSH = 'stk_push';
    const SOURCE_C2B      = 'c2b';

    /** Nothing about this receipt has been tied to a REQ. */
    const MATCH_UNMATCHED = 'unmatched';
    /** Every shilling has been applied to a bill. */
    const MATCH_MATCHED = 'matched';
    /** Tied to a REQ, but some or all of it is waiting for a bill to settle. */
    const MATCH_NEEDS_REVIEW = 'needs_review';
    /** The same receipt already settled a bill through STK push. */
    const MATCH_DUPLICATE = 'duplicate';
    /** Ops decided it is not ours to apply (wrong paybill, refunded, test). */
    const MATCH_DISMISSED = 'dismissed';

    /** Still waiting on somebody — the ops queue. */
    const OPEN_MATCH_STATUSES = [self::MATCH_UNMATCHED, self::MATCH_NEEDS_REVIEW];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function matchedBy()
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function scopeOpenPaybill($query)
    {
        return $query->where('source', self::SOURCE_C2B)
            ->whereIn('match_status', self::OPEN_MATCH_STATUSES);
    }

    /** What has come in on this receipt and not yet been applied to a bill. */
    public function getUnappliedAmountAttribute(): float
    {
        if ($this->source !== self::SOURCE_C2B
            || in_array($this->match_status, [self::MATCH_DUPLICATE, self::MATCH_DISMISSED], true)) {
            return 0.0;
        }

        return max(0, round((float) $this->amount - (float) $this->allocated_amount, 2));
    }
}
