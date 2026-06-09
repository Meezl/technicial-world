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
        'service_request_id',
        'urgency',
        'notes',
        'status',
    ];

    protected $casts = [
    ];

    public function items()
    {
        return $this->hasMany(ToolRequestItem::class);
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    // DecidedBy and Tool relations are removed from here as they are now per-item

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
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
