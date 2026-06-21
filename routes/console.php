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
