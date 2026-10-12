<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Logout;

/**
 * The other end of the session LogUserLogin opened.
 *
 * Worth its own row rather than being inferred from the gap after the last
 * action: a session that was signed out of and one that was abandoned on a
 * shared machine look identical without it.
 */
class LogUserLogout
{
    public function handle(Logout $event): void
    {
        $user = $event->user;

        if (!$user instanceof User) {
            return;
        }

        try {
            AuditLog::logForUser(AuditLog::ACTION_LOGOUT, $user, [
                'role' => $user->role,
                'guard' => $event->guard,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Logout audit failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
