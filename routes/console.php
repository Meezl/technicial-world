<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-raise final payment requests once per hour (#13).
Schedule::command('payments:raise-final')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Warn before an advance authorisation lapses, and report the ones that have.
// Hourly rather than daily: the warning window is set in hours, and a job that
// starts running uncovered overnight should not wait until morning to surface.
// Each authorisation is only ever notified once — see the notification marks
// on job_authorisations.
Schedule::command('authorisations:sweep')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// One consolidated progress report per management company per day.
//
// Hourly rather than daily because each account chooses the hour its own day
// ends at — the command sends only to the ones whose hour has come, and skips
// any that already had today's. See CorporateDigestService::dueNow().
Schedule::command('corporate:daily-reports')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Remind the office about new client requests nobody has acted on, every two
// hours per request. The sweep runs often so each reminder lands close to its
// two-hour mark; the command itself decides which requests are due.
Schedule::command('rfq:remind-unactioned')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Remind clients about quotations, payments, sign-offs and dates waiting on
// them, and technicians about assignments they have not answered — every 12
// hours per item until it is done. Runs often so each lands near its mark.
Schedule::command('reminders:send-actions')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();
