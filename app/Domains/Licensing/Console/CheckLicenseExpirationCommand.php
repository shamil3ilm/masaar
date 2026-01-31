<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Console;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Check for expiring licenses and update expired ones.
 *
 * This command should be scheduled to run daily.
 */
class CheckLicenseExpirationCommand extends Command
{
    protected $signature = 'license:check-expiration';

    protected $description = 'Check for expiring licenses and mark expired ones';

    public function handle(): int
    {
        $this->info('Checking license expirations...');

        // Mark expired licenses
        $expired = $this->markExpiredLicenses();
        $this->info("Marked {$expired} licenses as expired.");

        // Find licenses expiring soon
        $warningDays = config('licensing.notifications.expiry_warning_days', [30, 14, 7, 3, 1]);

        foreach ($warningDays as $days) {
            $expiring = $this->getExpiringLicenses($days);

            if ($expiring > 0) {
                $this->warn("Found {$expiring} licenses expiring in {$days} days.");

                Log::info("Licenses expiring soon", [
                    'days' => $days,
                    'count' => $expiring,
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Mark expired licenses.
     */
    private function markExpiredLicenses(): int
    {
        return License::where('status', LicenseStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => LicenseStatus::Expired,
            ]);
    }

    /**
     * Get count of licenses expiring in X days.
     */
    private function getExpiringLicenses(int $days): int
    {
        $startOfDay = now()->addDays($days)->startOfDay();
        $endOfDay = now()->addDays($days)->endOfDay();

        return License::where('status', LicenseStatus::Active)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$startOfDay, $endOfDay])
            ->count();
    }
}
