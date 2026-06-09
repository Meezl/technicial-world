<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    protected $fillable = [
        'checkout_request_id',
        'merchant_request_id',
        'receipt_number',
        'amount',
        'phone_number',
        'result_code',
        'result_desc',
        'transaction_date',
    ];
}
