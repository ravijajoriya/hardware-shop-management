<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expired logins are refused anyway; this clears them out of the database. It runs where
// Laravel's scheduler runs ("php artisan schedule:run" every minute from cron).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
