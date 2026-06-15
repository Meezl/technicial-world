<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    protected $fillable = [
        'payment_request_id',
        'checkout_request_id',
        'merchant_request_id',
        'receipt_number',
        'amount',
        'phone_number',
        'result_code',
        'result_desc',
        'transaction_date',
        'status',
        'source',
        'bill_ref_number',
        'payer_name',
        'reconciled',
    ];

    protected $casts = [
        'reconciled' => 'boolean',
        'amount'     => 'decimal:2',
    ];

    const STATUS_INITIATED = 'initiated';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    const SOURCE_STK_PUSH = 'stk_push';
    const SOURCE_C2B      = 'c2b';

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
