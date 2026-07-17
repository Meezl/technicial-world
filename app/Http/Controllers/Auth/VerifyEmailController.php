<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if (!$request->user()->hasVerifiedEmail() && $request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return $this->safeLandingRedirect($request);
    }

    /**
     * Clients must always land on /client/* — never follow an intended URL
     * that could be admin/pm/technician (would trip RoleMiddleware).
     * Non-clients keep the intended-URL behaviour so PMs / admins / techs
     * still get returned to the page they were originally trying to reach.
     */
    private function safeLandingRedirect(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->role === User::ROLE_CLIENT) {
            $request->session()->forget('url.intended');
            return redirect()->route('client.dashboard', ['verified' => 1]);
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
