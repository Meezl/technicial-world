<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expenditure extends Model
{
    protected $fillable = [
        'expenditure_id',
        'service_request_id',
        'category',
        'description',
        'amount',
        'vendor',
        'receipt_reference',
        'recorded_by',
        'expense_date',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    public static function generateExpenditureId(): string
    {
        $prefix = 'EXP';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}-{$date}-{$random}";
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
