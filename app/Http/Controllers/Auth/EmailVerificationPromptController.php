<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        if (!$user->hasVerifiedEmail()) {
            return Inertia::render('Auth/VerifyEmail', ['status' => session('status')]);
        }

        // Clients must always land on /client/* — never follow an intended
        // URL that could be admin/pm/technician (would trip RoleMiddleware
        // with a scary 403). Discard any stale intended for clients.
        if ($user->role === User::ROLE_CLIENT) {
            $request->session()->forget('url.intended');
            return redirect()->route('client.dashboard');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
