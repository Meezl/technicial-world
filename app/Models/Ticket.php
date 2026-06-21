<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'ticket_ref', 'user_id',
        'filer_name', 'filer_email', 'filer_phone',
        'category', 'urgency', 'location', 'subject', 'description',
        'status', 'resolution_summary',
        'resolved_by', 'resolved_at',
        'closed_by',   'closed_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    // Status state machine
    const STATUS_OPEN        = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED    = 'resolved';
    const STATUS_CLOSED      = 'closed';

    // Categories — kept narrow per client direction (electrical / plumbing / other)
    const CATEGORY_ELECTRICAL = 'electrical';
    const CATEGORY_PLUMBING   = 'plumbing';
    const CATEGORY_OTHER      = 'other';

    // Urgencies
    const URGENCY_EMERGENCY = 'emergency';
    const URGENCY_URGENT    = 'urgent';
    const URGENCY_NORMAL    = 'normal';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(TicketStatusLog::class)->orderBy('created_at');
    }

    public static function generateRef(): string
    {
        do {
            $ref = 'TKT-' . strtoupper(substr(uniqid(), -7));
        } while (self::where('ticket_ref', $ref)->exists());
        return $ref;
    }

    public function urgencyLabel(): string
    {
        return match ($this->urgency) {
            self::URGENCY_EMERGENCY => '🚨 Emergency',
            self::URGENCY_URGENT    => '⚠ Urgent',
            self::URGENCY_NORMAL    => 'Normal',
            default                 => ucfirst((string) $this->urgency),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN        => 'Open',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_RESOLVED    => 'Resolved',
            self::STATUS_CLOSED      => 'Closed',
            default                  => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }
}
