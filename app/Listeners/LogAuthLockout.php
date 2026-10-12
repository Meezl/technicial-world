<?php

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Auth\Events\Lockout;

/**
 * The throttle closed the door.
 *
 * Distinct from a run of failures: this is the point at which somebody who
 * was genuinely locked out starts ringing the office, and the entry is what
 * answers them. It is also the line past which a run of attempts stops being
 * somebody mistyping their password.
 */
class LogAuthLockout
{
    public function handle(Lockout $event): void
    {
        try {
            AuditLog::logAnonymous(AuditLog::ACTION_LOCKOUT, array_filter([
                'email' => $event->request->input('email'),
                'ip' => $event->request->ip(),
            ], fn ($value) => $value !== null));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Lockout audit failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
