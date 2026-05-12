<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Role-based redirect — always go to the correct dashboard for the role
        $user = Auth::user();
        $requisitionRoles = ['foreman', 'office', 'procurement', 'accounts'];

        $destination = match (true) {
            $user->role === 'admin' => route('admin.dashboard', absolute: false),
            $user->role === 'project_manager' => route('pm.dashboard', absolute: false),
            $user->role === 'technician' => route('technician.dashboard', absolute: false),
            $user->role === 'client' => route('client.dashboard', absolute: false),
            in_array($user->role, $requisitionRoles) => route('admin.requisitions.index', absolute: false),
            default => route('dashboard', absolute: false),
        };

        // Clear any stale intended URL that might point to a different role's route
        $request->session()->forget('url.intended');

        return redirect()->to($destination);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
