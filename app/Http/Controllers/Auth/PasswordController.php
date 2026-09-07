<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        return back();
    }

    /**
     * The forced change for an account still on its issued password.
     *
     * Separate from update() above because that one lives in profile settings
     * and asks for the current password as a re-authentication step. Here the
     * user has just signed in with the mailed password and is being made to
     * replace it, so asking them to retype it proves nothing — they read it
     * off the screen a moment ago.
     */
    public function showChangeForm(): \Inertia\Response|RedirectResponse
    {
        if (!request()->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return \Inertia\Inertia::render('Auth/ChangePassword');
    }

    public function changeForced(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user->must_change_password) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        // The issued password is the one thing it definitely must not become.
        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Choose a different password from the one that was emailed to you.',
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        return redirect()->route('dashboard')->with('success', 'Password set. Welcome to Technician World.');
    }
}
