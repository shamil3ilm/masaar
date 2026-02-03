<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Licensing\UsageReportingService;
use Illuminate\Console\Command;

/**
 * Report platform usage metrics to license server.
 *
 * Run hourly via scheduler:
 *   $schedule->command('license:report-usage')->hourly();
 */
class ReportPlatformUsage extends Command
{
    protected $signature = 'license:report-usage
                            {--show : Show metrics without reporting}';

    protected $description = 'Report platform usage metrics to the license server';

    public function handle(UsageReportingService $usageService): int
    {
        if ($this->option('show')) {
            $metrics = $usageService->collectMetrics();

            $this->info('Current Usage Metrics');
            $this->newLine();
            $this->table(
                ['Metric', 'Value'],
                collect($metrics)->map(fn ($value, $key) => [$key, is_array($value) ? json_encode($value) : $value])->toArray()
            );

            return Command::SUCCESS;
        }

        $this->info('Reporting usage metrics...');

        $result = $usageService->report();

        if ($result['success']) {
            $this->info('✓ Usage reported successfully');
            $this->newLine();

            if (isset($result['metrics'])) {
                $this->line('  Invoices created: ' . ($result['metrics']['invoices_created'] ?? 0));
                $this->line('  Invoices submitted: ' . ($result['metrics']['invoices_submitted'] ?? 0));
                $this->line('  Organizations: ' . ($result['metrics']['organizations_count'] ?? 0));
                $this->line('  API calls: ' . ($result['metrics']['api_calls'] ?? 0));
            }

            return Command::SUCCESS;
        }

        $this->error('✗ Failed to report usage: ' . $result['message']);
        return Command::FAILURE;
    }
}
