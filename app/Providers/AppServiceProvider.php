<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // NOTE: do not register listeners from app/Listeners here. Laravel
        // auto-discovers them from their handle() type-hint, so an explicit
        // Event::listen() binds them a second time and the handler runs
        // twice. LogSentEmail was registered here and was writing two
        // email_logs rows for every message sent.
        //
        // Currently discovered:
        //   MessageSent -> LogSentEmail   (audit copy of every email, #5)
        //   Verified    -> SendWelcomeEmail (welcome email, post-verification)
    }
}
