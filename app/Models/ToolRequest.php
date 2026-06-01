<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ToolRequest extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    const URGENCY_LOW = 'low';
    const URGENCY_NORMAL = 'normal';
    const URGENCY_HIGH = 'high';

    protected $fillable = [
        'technician_id',
        'tool_id',
        'tool_name_requested',
        'service_request_id',
        'quantity',
        'urgency',
        'notes',
        'status',
        'decided_by',
        'decided_at',
        'decision_notes',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function tool()
    {
        return $this->belongsTo(Tool::class);
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
