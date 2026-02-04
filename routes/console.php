<?php

use App\Services\Auth\LoginAttemptService;
use App\Services\Auth\TokenBlacklistService;
use App\Services\Core\UserLifecycleService;
use App\Services\Security\IdempotencyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Security Cleanup Commands
|--------------------------------------------------------------------------
*/

Artisan::command('security:cleanup-tokens', function (TokenBlacklistService $service) {
    $deleted = $service->cleanupExpired();
    $this->info("Cleaned up {$deleted} expired blacklisted tokens.");
})->purpose('Remove expired entries from token blacklist');

Artisan::command('security:cleanup-login-attempts {--days=7}', function (LoginAttemptService $service) {
    $days = (int) $this->option('days');
    $deleted = $service->cleanupOldAttempts($days);
    $this->info("Cleaned up {$deleted} login attempts older than {$days} days.");
})->purpose('Remove old login attempt records');

Artisan::command('security:cleanup-idempotency', function (IdempotencyService $service) {
    $deleted = $service->cleanupExpired();
    $this->info("Cleaned up {$deleted} expired idempotency keys.");
})->purpose('Remove expired idempotency keys');

Artisan::command('security:cleanup-sessions', function (UserLifecycleService $service) {
    $deleted = $service->cleanupExpiredSessions();
    $this->info("Cleaned up {$deleted} expired user sessions.");
})->purpose('Remove expired user sessions');

Artisan::command('security:cleanup-all', function () {
    $this->call('security:cleanup-tokens');
    $this->call('security:cleanup-login-attempts');
    $this->call('security:cleanup-idempotency');
    $this->call('security:cleanup-sessions');
})->purpose('Run all security cleanup tasks');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

Schedule::command('security:cleanup-all')->daily()->at('03:00');
Schedule::command('audit-logs:cleanup --days=90')->weekly()->sundays()->at('04:00');

// Scheduled Reports
Schedule::command('reports:run-scheduled --schedule=daily')->dailyAt('06:00');
Schedule::command('reports:run-scheduled --schedule=weekly')->weeklyOn(1, '06:00'); // Monday
Schedule::command('reports:run-scheduled --schedule=monthly')->monthlyOn(1, '06:00');
Schedule::command('reports:run-scheduled --schedule=quarterly')->quarterly()->at('06:00');

// Export cleanup
Schedule::command('exports:cleanup')->daily()->at('02:00');

// Webhook processing
Schedule::command('webhooks:process --retry')->everyFiveMinutes();
Schedule::command('webhooks:process --cleanup --days=30')->daily()->at('03:30');
