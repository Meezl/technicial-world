<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replacement for Laravel's built-in `verified` middleware that grants a
 * 7-day grace period after account creation. After day 7, unverified users
 * are redirected to the verification notice with a clear explanation +
 * link to resend the verification email.
 */
class VerifyAccountWithinGracePeriod
{
    const GRACE_DAYS = 7;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Already verified — let through
        if ($user->hasVerifiedEmail()) {
            return $next($request);
        }

        $createdAt = $user->created_at ? Carbon::parse($user->created_at) : null;
        $daysSinceSignup = $createdAt ? (int) $createdAt->diffInDays(now()) : self::GRACE_DAYS + 1;
        $daysRemaining = max(0, self::GRACE_DAYS - $daysSinceSignup);

        // Within grace — allow access, but flash a banner the layout can show
        if ($daysSinceSignup < self::GRACE_DAYS) {
            $request->session()->flash('verification_grace_remaining', $daysRemaining);
            return $next($request);
        }

        // Grace expired — redirect to verification notice with a clear reason
        return redirect()->route('verification.notice')->with(
            'verification_denied_reason',
            'Your free 7-day access window has ended. Please verify your email address to continue using your account. ' .
            'We sent the verification link to ' . $user->email . ' — click below to resend it.'
        );
    }
}
