<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hold an account on the password-change screen until it has its own password.
 *
 * Admin-created accounts arrive on a password that was mailed to them, so it
 * has passed through at least the user's mailbox and the office archive. It
 * works exactly once — for setting a real one.
 *
 * Deliberately applied to page requests only. Blocking the profile endpoints
 * would make the change itself impossible, and blocking logout would trap a
 * user who opened the mail on the wrong device.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->must_change_password) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json([
                'error' => 'Set your own password before continuing.',
            ], 423);
        }

        return redirect()->route('password.change')
            ->with('warning', 'Please set your own password before continuing.');
    }

    /** Routes that must stay reachable, or the user cannot get out of here. */
    private function isExempt(Request $request): bool
    {
        return $request->routeIs(
            'password.change',
            'password.change.update',
            'logout',
            'login',
            'verification.*',
        );
    }
}
