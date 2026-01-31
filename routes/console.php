<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Compliance Scheduled Tasks
|--------------------------------------------------------------------------
|
| These tasks support ZATCA compliance monitoring and maintenance.
| See docs/PRODUCTION-READINESS.md for operational guidelines.
|
*/

// Index health check - runs every 15 minutes
Schedule::command('compliance:index-health --alert')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/index-health.log'));

// Partition maintenance - runs monthly on the 1st at 3 AM
Schedule::command('compliance:partition-maintenance --create-future --months-ahead=2')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/partition-maintenance.log'));

/*
|--------------------------------------------------------------------------
| Licensing Scheduled Tasks
|--------------------------------------------------------------------------
|
| These tasks support license management and usage tracking.
|
*/

// Clean up expired rate limits - runs hourly
Schedule::command('license:cleanup-rate-limits')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/license-cleanup.log'));

// Check license expirations - runs daily at midnight
Schedule::command('license:check-expiration')
    ->dailyAt('00:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/license-expiration.log'));
