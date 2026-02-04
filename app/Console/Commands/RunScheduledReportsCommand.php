<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExecuteScheduledReportJob;
use App\Models\Reports\SavedReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunScheduledReportsCommand extends Command
{
    protected $signature = 'reports:run-scheduled
                            {--schedule= : Filter by schedule type (daily, weekly, monthly, quarterly)}
                            {--organization= : Filter by organization ID}
                            {--force : Run even if not due}
                            {--sync : Run synchronously instead of queueing}';

    protected $description = 'Run scheduled reports that are due for execution';

    public function handle(): int
    {
        $this->info('Checking for scheduled reports...');

        $query = SavedReport::where('is_scheduled', true)
            ->where('is_active', true);

        // Filter by schedule type
        if ($schedule = $this->option('schedule')) {
            $query->where('schedule', $schedule);
        }

        // Filter by organization
        if ($orgId = $this->option('organization')) {
            $query->where('organization_id', $orgId);
        }

        // Filter by due time unless forced
        if (!$this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            });
        }

        $reports = $query->get();

        if ($reports->isEmpty()) {
            $this->info('No scheduled reports due for execution.');
            return self::SUCCESS;
        }

        $this->info("Found {$reports->count()} report(s) to execute.");

        $bar = $this->output->createProgressBar($reports->count());
        $bar->start();

        $successful = 0;
        $failed = 0;

        foreach ($reports as $report) {
            try {
                $this->executeReport($report);
                $successful++;
            } catch (\Throwable $e) {
                $this->error("\nFailed to execute report '{$report->name}': {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Completed: {$successful} successful, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function executeReport(SavedReport $report): void
    {
        $this->line("\n  Processing: {$report->name} ({$report->report_type})");

        if ($this->option('sync')) {
            // Run synchronously
            $job = new ExecuteScheduledReportJob($report);
            $job->handle(app(\App\Services\Reports\ReportExportService::class));
        } else {
            // Dispatch to queue
            ExecuteScheduledReportJob::dispatch($report);
        }

        $this->line("  → Dispatched for execution");
    }
}
