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

// Report usage metrics to license server - runs hourly
Schedule::command('license:report-usage')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => config('platform-license.enabled', false))
    ->appendOutputTo(storage_path('logs/license-usage.log'));

/*
|--------------------------------------------------------------------------
| ZATCA Offline Queue Scheduled Tasks
|--------------------------------------------------------------------------
|
| These tasks handle offline mode recovery and queue processing.
| Critical for POS/retail scenarios with intermittent connectivity.
|
*/

// Process offline queue - runs every 5 minutes when auto-recovery enabled
Schedule::command('fatoora:process-offline --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => config('zatca.offline.auto_recovery.enabled', true))
    ->appendOutputTo(storage_path('logs/zatca-offline-queue.log'));

// Check certificate expiry - runs daily at 8 AM with notifications
Schedule::command('fatoora:check-certificate --notify')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/zatca-certificate.log'));

// Verify hash chain integrity - runs weekly on Sunday at 2 AM
Schedule::command('fatoora:verify-hash-chain')
    ->weeklyOn(0, '02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/zatca-hash-chain.log'));

// Clean up old offline queue items - runs daily at 4 AM
Schedule::command('compliance:cleanup-offline-queue')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/zatca-queue-cleanup.log'));
