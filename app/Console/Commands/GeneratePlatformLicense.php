<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Licensing\Services\PlatformLicenseService;
use Illuminate\Console\Command;

/**
 * Generate platform license keys for partners.
 *
 * Usage:
 *   php artisan license:generate TAXFLY --type=TRIAL --days=30
 *   php artisan license:generate ACME --type=PROD --expires=2027-12-31
 */
class GeneratePlatformLicense extends Command
{
    protected $signature = 'license:generate
                            {partner : Partner identifier (e.g., TAXFLY, ACME)}
                            {--type=TRIAL : License type (TRIAL, PROD, DEV)}
                            {--days=30 : Number of days until expiration}
                            {--expires= : Specific expiration date (YYYY-MM-DD)}';

    protected $description = 'Generate a platform license key for a partner';

    public function handle(PlatformLicenseService $licenseService): int
    {
        $partner = strtoupper($this->argument('partner'));
        $type = strtoupper($this->option('type'));

        // Validate type
        $validTypes = [
            PlatformLicenseService::TYPE_TRIAL,
            PlatformLicenseService::TYPE_PRODUCTION,
            PlatformLicenseService::TYPE_DEVELOPMENT,
        ];

        if (! in_array($type, $validTypes)) {
            $this->error("Invalid license type: {$type}");
            $this->line('Valid types: '.implode(', ', $validTypes));

            return Command::FAILURE;
        }

        // Calculate expiration date
        if ($this->option('expires')) {
            try {
                $expiresAt = new \DateTime($this->option('expires'));
            } catch (\Exception $e) {
                $this->error('Invalid expiration date format. Use YYYY-MM-DD');

                return Command::FAILURE;
            }
        } else {
            $days = (int) $this->option('days');
            if ($days < 1) {
                $this->error('Days must be at least 1');

                return Command::FAILURE;
            }
            $expiresAt = new \DateTime("+{$days} days");
        }

        // Generate the license key
        $licenseKey = $licenseService->generateKey($partner, $type, $expiresAt);

        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║           PLATFORM LICENSE GENERATED                       ║');
        $this->info('╠════════════════════════════════════════════════════════════╣');
        $this->newLine();

        $this->line("  <fg=cyan>Partner:</fg=cyan>     {$partner}");
        $this->line("  <fg=cyan>Type:</fg=cyan>        {$type}");
        $this->line('  <fg=cyan>Expires:</fg=cyan>     '.$expiresAt->format('Y-m-d'));
        $this->line('  <fg=cyan>Days:</fg=cyan>        '.(new \DateTime)->diff($expiresAt)->days);
        $this->newLine();

        $this->info('  License Key:');
        $this->newLine();
        $this->line("  <fg=green;options=bold>{$licenseKey}</fg=green;options=bold>");
        $this->newLine();

        $this->info('╠════════════════════════════════════════════════════════════╣');
        $this->info('║  Instructions for partner:                                 ║');
        $this->info('╠════════════════════════════════════════════════════════════╣');
        $this->newLine();
        $this->line('  Add to .env file:');
        $this->newLine();
        $this->line("  <fg=yellow>PLATFORM_LICENSE_KEY={$licenseKey}</fg=yellow>");
        $this->newLine();
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Log the generation
        $this->logGeneration($partner, $type, $expiresAt, $licenseKey);

        return Command::SUCCESS;
    }

    private function logGeneration(string $partner, string $type, \DateTime $expiresAt, string $key): void
    {
        $logFile = storage_path('logs/license-generations.log');
        $logEntry = sprintf(
            "[%s] Generated %s license for %s, expires %s, key: %s\n",
            now()->toIso8601String(),
            $type,
            $partner,
            $expiresAt->format('Y-m-d'),
            substr($key, 0, 20).'...'
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
