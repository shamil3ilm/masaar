<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Licensing\Services\PlatformLicenseService;
use Illuminate\Console\Command;

/**
 * Check and display platform license status.
 */
class CheckPlatformLicense extends Command
{
    protected $signature = 'license:status
                            {--clear-cache : Clear cached validation result}';

    protected $description = 'Check the platform license status';

    public function handle(PlatformLicenseService $licenseService): int
    {
        if ($this->option('clear-cache')) {
            $licenseService->clearCache();
            $this->info('License cache cleared.');
        }

        $this->newLine();
        $this->info('Checking platform license...');
        $this->newLine();

        $result = $licenseService->validate();

        if ($result['valid']) {
            $this->info('╔════════════════════════════════════════════════════════════╗');
            $this->info('║                    LICENSE VALID ✓                         ║');
            $this->info('╚════════════════════════════════════════════════════════════╝');
            $this->newLine();

            $this->line("  <fg=cyan>Partner:</fg=cyan>            " . ($result['partner'] ?? 'N/A'));
            $this->line("  <fg=cyan>Type:</fg=cyan>               " . ($result['type'] ?? 'N/A'));
            $this->line("  <fg=cyan>Expires:</fg=cyan>            " . ($result['expires_at'] ?? 'N/A'));
            $this->line("  <fg=cyan>Days Remaining:</fg=cyan>     " . ($result['days_remaining'] ?? 'N/A'));
            $this->line("  <fg=cyan>Validation Method:</fg=cyan>  " . ($result['validation_method'] ?? 'N/A'));

            if (!empty($result['features'])) {
                $this->newLine();
                $this->line('  <fg=cyan>Features:</fg=cyan>');
                foreach ($result['features'] as $feature => $value) {
                    $displayValue = $value === -1 ? 'Unlimited' : $value;
                    $this->line("    - {$feature}: {$displayValue}");
                }
            }

            // Warning if expiring soon
            if ($result['days_remaining'] !== null && $result['days_remaining'] <= 7) {
                $this->newLine();
                $this->warn("  ⚠️  License expires in {$result['days_remaining']} days!");
                $this->line('  Contact sales@masaar.com to renew.');
            }

            $this->newLine();
            return Command::SUCCESS;
        } else {
            $this->error('╔════════════════════════════════════════════════════════════╗');
            $this->error('║                  LICENSE INVALID ✗                         ║');
            $this->error('╚════════════════════════════════════════════════════════════╝');
            $this->newLine();

            $this->line("  <fg=red>Error:</fg=red> " . $result['message']);
            $this->newLine();
            $this->line('  To resolve:');
            $this->line('  1. Ensure PLATFORM_LICENSE_KEY is set in .env');
            $this->line('  2. Verify the license has not expired');
            $this->line('  3. Contact sales@masaar.com for assistance');
            $this->newLine();

            return Command::FAILURE;
        }
    }
}
