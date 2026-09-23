<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An outstanding ask of a client or technician, and how often they have been
 * reminded about it. See the create_action_reminders_table migration.
 */
class ActionReminder extends Model
{
    protected $fillable = [
        'remindable_type', 'remindable_id', 'kind',
        'awaiting_since', 'reminder_count', 'last_reminded_at', 'resolved_at',
    ];

    protected $casts = [
        'awaiting_since' => 'datetime',
        'last_reminded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reminder_count' => 'integer',
    ];

    /** A retail client, or a corporate approval stage, deciding on a quotation. */
    const KIND_QUOTE_DECISION = 'quote_decision';

    /** A client paying a payment request. */
    const KIND_PAYMENT = 'payment';

    /** A client signing off completed work. */
    const KIND_COMPLETION_VERIFICATION = 'completion_verification';

    /** A client answering a proposed visit date. */
    const KIND_DATE_RESPONSE = 'date_response';

    /** A technician accepting or declining an assignment. */
    const KIND_ASSIGNMENT_RESPONSE = 'assignment_response';

    const CLIENT_KINDS = [
        self::KIND_QUOTE_DECISION,
        self::KIND_PAYMENT,
        self::KIND_COMPLETION_VERIFICATION,
        self::KIND_DATE_RESPONSE,
    ];

    /** Hours between the ask and the first reminder, and between reminders. */
    const INTERVAL_HOURS = 12;

    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOutstanding($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Start the clock on a fresh ask. Any earlier open ask of the same kind on
     * the same record is closed — a revised quotation is a new question, and
     * the client should get the full 12 hours to look at it.
     */
    public static function openFor(Model $remindable, string $kind): self
    {
        static::closeFor($remindable, $kind);

        return static::create([
            'remindable_type' => $remindable->getMorphClass(),
            'remindable_id' => $remindable->getKey(),
            'kind' => $kind,
            'awaiting_since' => now(),
        ]);
    }

    public static function closeFor(Model $remindable, string $kind): void
    {
        static::query()->outstanding()
            ->where('remindable_type', $remindable->getMorphClass())
            ->where('remindable_id', $remindable->getKey())
            ->where('kind', $kind)
            ->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    /** Due when the next 12-hour mark since the ask has passed. */
    public function isDue(): bool
    {
        return $this->awaiting_since
            ->copy()
            ->addHours(self::INTERVAL_HOURS * ($this->reminder_count + 1))
            ->lte(now());
    }

    /** Restart the clock, e.g. when a corporate quote moves to its next stage. */
    public function restart(\Carbon\CarbonInterface $since): void
    {
        $this->update(['awaiting_since' => $since, 'reminder_count' => 0, 'last_reminded_at' => null]);
    }
}
