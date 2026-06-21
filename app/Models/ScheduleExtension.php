<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleExtension extends Model
{
    protected $fillable = [
        'service_request_id', 'requested_by',
        'additional_days', 'requested_target_at', 'reason',
        'status',
        'admin_decided_at', 'admin_decided_by', 'admin_note',
        'client_decided_at', 'client_note',
    ];

    protected $casts = [
        'requested_target_at' => 'datetime',
        'admin_decided_at'    => 'datetime',
        'client_decided_at'   => 'datetime',
    ];

    const STATUS_PENDING        = 'pending';          // tech submitted, awaiting admin
    const STATUS_ADMIN_APPROVED = 'admin_approved';   // admin OK, awaiting client
    const STATUS_CLIENT_APPROVED = 'client_approved'; // final approval
    const STATUS_ADMIN_REJECTED  = 'admin_rejected';
    const STATUS_CLIENT_REJECTED = 'client_rejected';

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }

    public function adminDecider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'admin_decided_by');
    }
}
