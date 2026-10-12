<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_DELETED = 'deleted';
    const ACTION_STATE_CHANGED = 'state_changed';
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_PAYMENT = 'payment';
    const ACTION_APPROVAL = 'approval';
    const ACTION_ASSIGNMENT = 'assignment';
    const ACTION_REASSIGNMENT = 'reassignment';
    const ACTION_SUSPENSION = 'suspension';

    /**
     * The catch-all the audit middleware writes.
     *
     * Any authenticated write that finished without anything calling log()
     * lands here, so a route nobody remembered to instrument still leaves a
     * trace of who called it and with what. A log full of these is a sign
     * the named actions have fallen behind the routes, not that the audit
     * trail is working as intended.
     */
    const ACTION_REQUEST = 'request';

    /**
     * An action somebody was refused.
     *
     * Nothing changed, which is why these were left out at first — a log that
     * records refusals as though they happened is worse than one that omits
     * them. But "who tried" is a different question from "what happened", and
     * it is the one asked after the fact: a run of these against jobs that are
     * not somebody's is the only trace that anybody looked.
     */
    const ACTION_DENIED = 'denied';

    /**
     * Someone took a copy of something.
     *
     * Reads are not recorded, and should not be — the log would be nothing
     * else. A download is different: a technician's ID, a client revenue
     * export, an invoice PDF. The data leaves the platform and the only
     * record that it did is this one.
     */
    const ACTION_DOWNLOAD = 'download';

    /** A sign-in that failed, and the address it was tried against. */
    const ACTION_LOGIN_FAILED = 'login_failed';

    /** Too many failures: the throttle closed the door. */
    const ACTION_LOCKOUT = 'lockout';

    /**
     * How many entries this request has written.
     *
     * The audit middleware reads it to decide whether a write needs its
     * catch-all entry: an action that logged itself properly should not also
     * be recorded generically. Reset per request rather than per process,
     * because a queue worker or an Octane-style long-lived process handles
     * many requests in one PHP lifetime.
     */
    protected static int $writesThisRequest = 0;

    public static function resetRequestCounter(): void
    {
        static::$writesThisRequest = 0;
    }

    public static function writesThisRequest(): int
    {
        return static::$writesThisRequest;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create an audit log entry against a model.
     */
    public static function log(
        string $action,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): self {
        return self::record(
            $action,
            get_class($auditable),
            (int) $auditable->id,
            $oldValues,
            $newValues,
            $userId
        );
    }

    /**
     * Create an audit log entry without a model in hand.
     *
     * Logging in and out, and the middleware's catch-all, have no domain
     * object to hang off — the event is about the person, not a record. They
     * still need the actor, the address and the agent, which is the part of an
     * audit entry that actually settles a dispute, so they go through the same
     * writer rather than a parallel one.
     */
    /**
     * Create an audit log entry, with or without a subject.
     *
     * The subject is nullable because some of what belongs in here has none:
     * a sign-in attempt against an address that matches no account, a guest
     * submitting a public form. Writing a sentinel to keep the column full
     * would put a falsehood in the one table whose job is being accurate.
     */
    public static function record(
        string $action,
        ?string $auditableType = null,
        ?int $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): self {
        static::$writesThisRequest++;

        return self::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Log an event about a user themselves — signing in, signing out.
     */
    public static function logForUser(string $action, User $user, ?array $newValues = null): self
    {
        return self::record($action, User::class, (int) $user->id, null, $newValues, $user->id);
    }

    /**
     * An event with no account behind it — a failed sign-in, a guest's
     * submission. Recorded as the platform's own, with whatever identifies
     * the attempt in the values.
     */
    public static function logAnonymous(string $action, ?array $newValues = null): self
    {
        return self::record($action, null, null, null, $newValues, null);
    }
}
