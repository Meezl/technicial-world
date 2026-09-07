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
