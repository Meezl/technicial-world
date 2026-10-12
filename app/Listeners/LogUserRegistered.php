<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

/**
 * A new account appearing on the platform.
 *
 * Accounts the office creates are audited where they are created — see the
 * admin onboarding paths — but a client registering themselves wrote nothing,
 * so the most common way an account comes into existence was the one way it
 * could not be accounted for.
 *
 * Fires before the registration controller signs the new user in, so the
 * actor is passed explicitly rather than read from the guard.
 */
class LogUserRegistered
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (!$user instanceof User) {
            return;
        }

        try {
            AuditLog::logForUser(AuditLog::ACTION_CREATED, $user, [
                'event' => 'account_registered',
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'self_registered' => true,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Registration audit failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
