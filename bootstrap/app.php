<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'verified.grace' => \App\Http\Middleware\VerifyAccountWithinGracePeriod::class,
        ]);

        // Safaricom Daraja webhooks have no CSRF token — exempt them.
        // C2B paths avoid the "mpesa" keyword because Safaricom rejects it
        // on URL registration.
        $middleware->validateCsrfTokens(except: [
            'api/mpesa/callback',
            'api/transactions/c2b/confirmation',
            'api/transactions/c2b/validation',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Auth-failure handling for Inertia XHR requests.
        //
        // Laravel's default AuthenticationException handling issues a 302
        // redirect to route('login'). That works for a full-page browser
        // request but NOT for an Inertia SPA click — Inertia expects a
        // specific response envelope (X-Inertia headers), and a plain
        // 302 to an HTML login page breaks with a blank screen or
        // 'unexpected response' error.
        //
        // Detect the Inertia XHR case via the X-Inertia header and
        // respond with Inertia::location(), which sends a 409 + special
        // X-Inertia-Location header that the Inertia frontend
        // recognises and turns into a full-page navigation. Also seeds
        // url.intended so admins/PMs/techs are bounced back to whatever
        // they were trying to open after they log in again — clients
        // get their intended cleared at login per the client-routing
        // rule (see feedback_client_routing memory).
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->header('X-Inertia')) {
                session(['url.intended' => $request->fullUrl()]);
                return \Inertia\Inertia::location(route('login'));
            }
            // Fall through — Laravel's default handler will redirect
            // browser requests to route('login') as usual.
        });

        // An expired or re-clicked email-verification link renders Laravel's
        // bare "403 | Invalid signature." page — a dead end with no way
        // back, which is exactly where users land when they open the email
        // a day later or when a mail client rewrites the query string.
        // Send them to the verification prompt, which has a Resend button.
        $exceptions->render(function (\Illuminate\Routing\Exceptions\InvalidSignatureException $e, \Illuminate\Http\Request $request) {
            if (!$request->routeIs('verification.verify')) {
                return null; // Any other signed route keeps the hard failure.
            }

            if ($request->user()?->hasVerifiedEmail()) {
                return redirect()->route('verification.notice');
            }

            if (!$request->user()) {
                return redirect()->route('login')
                    ->with('error', 'That verification link has expired. Sign in and we will send you a fresh one.');
            }

            return redirect()->route('verification.notice')
                ->with('error', 'That verification link has expired or was already used. Tap "Resend Verification Email" for a new one.');
        });

        // Same treatment for CSRF/token-mismatch (419) failures. Sessions
        // that expire between page loads produce these on the next POST;
        // without this, Inertia sees a raw 419 and the user is stuck.
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            if ($request->header('X-Inertia')) {
                session(['url.intended' => $request->fullUrl()]);
                session()->flash('error', 'Your session expired. Please sign in again.');
                return \Inertia\Inertia::location(route('login'));
            }
            // Non-Inertia: fall through so the default 419 page renders.
        });
    })->create();
