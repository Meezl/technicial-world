<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'ticket_ref', 'user_id', 'service_request_id',
        'filer_name', 'filer_email', 'filer_phone',
        'category', 'urgency', 'location', 'subject', 'description',
        'status', 'resolution_summary',
        'resolved_by', 'resolved_at',
        'closed_by',   'closed_at',
        'type', 'fee_amount', 'charge_type', 'charge_reason',
        'fee_authorised_by', 'fee_authorised_at', 'created_by',
    ];

    protected $casts = [
        'resolved_at'       => 'datetime',
        'closed_at'         => 'datetime',
        'fee_amount'        => 'decimal:2',
        'fee_authorised_at' => 'datetime',
    ];

    /**
     * Set here rather than relying on the column defaults. A DB default is
     * not applied to the in-memory model, so a freshly created ticket would
     * report a null type and null charge_type until it was reloaded — and
     * isChargeable() would answer from those nulls. The same pattern bit
     * users.role, where a null role failed every permission check.
     */
    protected $attributes = [
        'type'        => self::TYPE_SUPPORT,
        'charge_type' => self::CHARGE_CHARGEABLE,
    ];

    // Ticket types
    const TYPE_SUPPORT = 'support';   // free enquiry or complaint, guest-fileable
    const TYPE_CALLOUT = 'callout';   // paid attendance

    // Why the ticket costs what it costs. Only CHARGE_CHARGEABLE bills.
    const CHARGE_CHARGEABLE = 'chargeable';
    const CHARGE_INCLUDED   = 'included';   // covered by the quoted work
    const CHARGE_WAIVED     = 'waived';     // chargeable, written off
    const CHARGE_WARRANTY   = 'warranty';   // our defect, never chargeable

    const ZERO_CHARGE_TYPES = [
        self::CHARGE_INCLUDED,
        self::CHARGE_WAIVED,
        self::CHARGE_WARRANTY,
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

    /** The job this ticket was raised under. Null for standalone tickets. */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ServiceRequestDocument::class);
    }

    public function feeAuthoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fee_authorised_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Does this ticket bill the client at all? Zero-charge tickets skip the
     * payment gate entirely rather than raising a KES 0 request — an empty
     * invoice in the portal and an M-Pesa prompt for nothing help nobody.
     */
    public function isChargeable(): bool
    {
        return $this->type === self::TYPE_CALLOUT
            && $this->charge_type === self::CHARGE_CHARGEABLE
            && (float) $this->fee_amount > 0;
    }

    /**
     * Free to the client. Not free to the business — the technician still
     * attends and is still paid, so the cost lands on the job regardless.
     */
    public function isZeroCharge(): bool
    {
        return in_array($this->charge_type, self::ZERO_CHARGE_TYPES, true);
    }

    /** Has the attendance fee been settled? Always true when nothing is owed. */
    public function isSettled(): bool
    {
        if (!$this->isChargeable()) {
            return true;
        }

        return $this->paymentRequests()
            ->where('status', PaymentRequest::STATUS_PAID)
            ->exists();
    }

    public function chargeTypeLabel(): string
    {
        return match ($this->charge_type) {
            self::CHARGE_CHARGEABLE => 'Chargeable',
            self::CHARGE_INCLUDED   => 'Included in quoted work',
            self::CHARGE_WAIVED     => 'Fee waived',
            self::CHARGE_WARRANTY   => 'Warranty visit',
            default                 => ucfirst((string) $this->charge_type),
        };
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
