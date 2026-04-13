<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionItem extends Model
{
    protected $fillable = [
        'requisition_id',
        'name',
        'quantity',
        'unit',
        'status',
        'supplier_name',
        'price',
        'currency',
        'notes',
        'quotation_file_path',
        'quotation_notes',
        'tracking_number',
        'expected_delivery_date',
        'actual_delivery_date',
        'acknowledged_at',
        'acknowledged_by',
        'delivery_condition_notes',
        'approved_by',
        'approved_at',
        'rejection_reason'
    ];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'acknowledged_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // State machine valid transitions
    public const VALID_TRANSITIONS = [
        'requested' => ['approved', 'rejected'],
        'approved' => ['procured', 'rejected'],
        'procured' => ['awaiting_payment'],
        'awaiting_payment' => ['paid', 'rejected'],
        'paid' => ['in_transit'],
        'in_transit' => ['delivered'],
        'delivered' => ['acknowledged'],
        'acknowledged' => ['closed'],
        'rejected' => [], // Terminal state
        'closed' => [], // Terminal state
    ];

    public function canTransitionTo($newStatus)
    {
        return in_array($newStatus, self::VALID_TRANSITIONS[$this->status] ?? []);
    }
}
