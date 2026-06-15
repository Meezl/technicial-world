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
        //
    })->create();
