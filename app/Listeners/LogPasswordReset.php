<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/**
 * A password actually changed through the reset link.
 *
 * The request for a link is an ordinary POST and the middleware records it.
 * This is the other end — the point at which the account's credentials
 * changed — and it is the entry that matters when somebody says they never
 * asked for a reset.
 */
class LogPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (!$user instanceof User) {
            return;
        }

        try {
            AuditLog::logForUser(AuditLog::ACTION_UPDATED, $user, [
                'event' => 'password_reset',
                'role' => $user->role,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Password reset audit failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
