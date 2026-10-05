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

    /**
     * How often each kind is chased, where it is not the standard 12 hours.
     *
     * Money is paced differently from everything else. A client who owes a
     * deposit is chased every 36 hours — three times, so the last lands inside
     * the first week — rather than the twice-daily mail that had clients
     * ringing to complain. The odd interval is deliberate: a fixed number of
     * days would put every reminder at the same hour of the morning, and a
     * client who ignores one at 8am tends to ignore the next one too.
     *
     * A quotation runs slower still, at 48 hours. Deciding whether to buy
     * takes longer than paying a deposit already agreed to, and the twice-daily
     * mail it replaces was the other half of what clients rang to complain
     * about.
     *
     * Sign-offs and proposed dates keep the 12-hour rhythm. Both are a single
     * yes away, both hold the job where it is until it comes, and neither asks
     * the client for anything but a moment.
     */
    const INTERVAL_HOURS_BY_KIND = [
        self::KIND_PAYMENT => 36,
        self::KIND_QUOTE_DECISION => 48,
    ];

    /**
     * How many times an ask of each kind may be chased, where there is a limit.
     *
     * Three for a payment, which with the 36-hour spacing above puts the last
     * one inside the first week. After that the mail stops and the office picks
     * it up: every client reminder sent also tells ops to follow up, so by the
     * third one somebody has had three prompts to make the call. A fourth
     * email was never going to be the thing that worked.
     *
     * Three for a quotation too, at 48 hours — day two, day four, day six. A
     * client who has not decided by then is not waiting to be reminded, and a
     * quotation chased indefinitely reads as pressure rather than service.
     *
     * Unlisted kinds have no limit: a sign-off or a date is a single yes that
     * holds the job where it is, and the chase is what unblocks it.
     */
    const MAX_REMINDERS = [
        self::KIND_PAYMENT => 3,
        self::KIND_QUOTE_DECISION => 3,
    ];

    /** Hours between chases for this ask. */
    public function intervalHours(): int
    {
        return self::INTERVAL_HOURS_BY_KIND[$this->kind] ?? self::INTERVAL_HOURS;
    }

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

    /** Due when the next 12-hour mark has passed and the kind has chases left. */
    public function isDue(): bool
    {
        if ($this->hasBeenChasedEnough()) {
            return false;
        }

        return $this->awaiting_since
            ->copy()
            ->addHours($this->intervalHours() * ($this->reminder_count + 1))
            ->lte(now());
    }

    /**
     * Said its piece. The ask stays outstanding — it is still owed, and the
     * office still sees it — but we stop writing to the client about it.
     */
    public function hasBeenChasedEnough(): bool
    {
        $limit = self::MAX_REMINDERS[$this->kind] ?? null;

        return $limit !== null && $this->reminder_count >= $limit;
    }

    /** Restart the clock, e.g. when a corporate quote moves to its next stage. */
    public function restart(\Carbon\CarbonInterface $since): void
    {
        $this->update(['awaiting_since' => $since, 'reminder_count' => 0, 'last_reminded_at' => null]);
    }
}
