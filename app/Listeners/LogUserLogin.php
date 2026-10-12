<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Who signed in, from where, on what.
 *
 * AuditLog has carried a `login` constant since the table was created and
 * nothing ever wrote one, so the trail began at a user's first action with no
 * record of the session it belonged to. That is the entry a dispute usually
 * turns on: a client denying an approval is denying the session, and the
 * address and agent stamped here are what tie the two together.
 *
 * Auto-discovered from the handle() type-hint — do not also register it in
 * AppServiceProvider, or every sign-in writes two rows (see the note there).
 */
class LogUserLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (!$user instanceof User) {
            return;
        }

        // Never allowed to take a sign-in down with it. A failure to write the
        // audit row is worth knowing about, but locking a client out of their
        // own portal over it is a worse outcome than a gap in the trail.
        try {
            AuditLog::logForUser(AuditLog::ACTION_LOGIN, $user, [
                'role' => $user->role,
                'guard' => $event->guard,
                'remember' => (bool) $event->remember,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Login audit failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
