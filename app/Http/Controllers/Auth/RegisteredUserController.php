<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'phone' => 'required|string|max:20',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Explicit role assignment. The users.role column has a DB-level
        // default of 'client', but if that default ever fails to apply
        // (schema drift, driver quirk, etc.) the row lands with role=NULL
        // and every downstream role check returns false — producing the
        // '403 unauthorized - insufficient role permissions' seen on
        // registration. Setting it here guarantees the correct role
        // regardless of DB defaults.
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => User::ROLE_CLIENT,
        ]);

        // Sends the "Verify Email Address" notification. The welcome email
        // is deliberately NOT sent here — it goes out on the Verified event
        // instead (see SendWelcomeEmail). Sending both at signup produced two
        // near-identical stock-template emails seconds apart, which clients
        // reported as "the verification email arrived twice", and its
        // "Go to Dashboard" button was a dead end for an unverified user.
        event(new Registered($user));

        Auth::login($user);

        // Wipe any stale 'intended' URL from before registration. If the
        // visitor had tried to hit a role-restricted route (say an admin
        // link shared in WhatsApp) they were redirected to /login and the
        // target got cached in session; without clearing it, the next
        // redirect after registration would bounce them straight into the
        // 403 wall from RoleMiddleware.
        $request->session()->forget('url.intended');

        return redirect(route('verification.notice'));
    }
}
