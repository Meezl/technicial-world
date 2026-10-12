<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;

/**
 * A sign-in that did not work, and the address it was tried against.
 *
 * Successful logins have been recorded since the trail was extended to cover
 * every role, which left the half of the question that actually matters
 * unanswered: a successful sign-in at 02:00 means one thing on its own and
 * something else after forty failures against the same address.
 *
 * The attempt may name no account at all, which is why audit entries no
 * longer require a subject — pointing a failure at a user that does not exist
 * would mean inventing one.
 *
 * The password is never touched. What is recorded is the address tried, which
 * is not a secret, and the address it came from, which is the part that makes
 * the entry worth having.
 */
class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        try {
            $user = $event->user instanceof User ? $event->user : null;

            // credentials carries the password; only the identifier is taken,
            // and never the rest of it.
            $identifier = $event->credentials['email']
                ?? $event->credentials['username']
                ?? null;

            AuditLog::record(
                AuditLog::ACTION_LOGIN_FAILED,
                $user ? User::class : null,
                $user?->id,
                null,
                array_filter([
                    'email' => $identifier,
                    'guard' => $event->guard,
                    // Whether the address belongs to anybody. A run of
                    // failures against real accounts reads differently from a
                    // run against addresses that do not exist.
                    'account_exists' => $user !== null,
                    'role' => $user?->role,
                ], fn ($value) => $value !== null),
                // Deliberately not attributed to the account being guessed at:
                // they did not do this, and a log that says they did would
                // accuse the victim of the attempt.
                null
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed-login audit failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
