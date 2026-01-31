<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Console;

use App\Domains\Licensing\Services\UsageMeteringService;
use Illuminate\Console\Command;

/**
 * Clean up expired rate limit records.
 *
 * This command should be scheduled to run hourly to prevent
 * the license_rate_limits table from growing unbounded.
 */
class CleanupLicenseRateLimitsCommand extends Command
{
    protected $signature = 'license:cleanup-rate-limits';

    protected $description = 'Clean up expired license rate limit records';

    public function handle(UsageMeteringService $meteringService): int
    {
        $this->info('Cleaning up expired rate limits...');

        $deleted = $meteringService->cleanupExpiredRateLimits();

        $this->info("Deleted {$deleted} expired rate limit records.");

        return self::SUCCESS;
    }
}
