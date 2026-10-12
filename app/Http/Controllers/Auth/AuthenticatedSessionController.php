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
            $user->role === 'storeman' => route('admin.tools', absolute: false),
            in_array($user->role, $requisitionRoles) => route('admin.requisitions.index', absolute: false),
            default => route('dashboard', absolute: false),
        };

        // Where they were actually trying to go, if it belongs to them.
        //
        // This was cleared outright, for a sound reason badly applied: an
        // intended URL left over from another role would drop a client into
        // admin routes. But forgetting it unconditionally breaks the commonest
        // journey the platform has — a client opens the quotation email,
        // clicks through, is asked to sign in, and lands on their dashboard
        // with no idea where the quotation went. They clicked "Approve" and
        // arrived somewhere unrelated, which is a large part of why clients
        // report approving quotes the office never received.
        //
        // Honouring it only inside the role's own area keeps the rule that put
        // this line here — a client can never be sent anywhere but /client/* —
        // and still takes them to the thing they asked for.
        $intended = $this->intendedWithin($request, $user->role);

        $request->session()->forget('url.intended');

        return redirect()->to($intended ?? $destination);
    }

    /**
     * The intended URL, but only when it sits inside this role's own area.
     *
     * Compared on the path alone: an absolute URL from another host is not
     * somewhere this application should be sending anybody.
     */
    private function intendedWithin(Request $request, ?string $role): ?string
    {
        $intended = $request->session()->get('url.intended');

        if (!is_string($intended) || $intended === '') {
            return null;
        }

        $prefix = match ($role) {
            'client' => '/client/',
            'technician' => '/technician/',
            'project_manager' => '/pm/',
            'admin' => '/admin/',
            default => null,
        };

        if ($prefix === null) {
            return null;
        }

        $parts = parse_url($intended);

        // Off-site, or unparseable. Neither is a destination.
        if ($parts === false || (isset($parts['host']) && $parts['host'] !== $request->getHost())) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        // Rebuilt from the parts we trust rather than echoed back, so a
        // crafted value cannot carry anything but a path and a query.
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
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
