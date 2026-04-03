<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Fatoora\Services\FallbackHandler;
use Illuminate\Console\Command;

/**
 * Command to replay operations that failed due to temporary outages.
 *
 * Usage:
 *   php artisan zatca:replay-failed
 *   php artisan zatca:replay-failed --dry-run
 */
class ReplayFailedOperations extends Command
{
    protected $signature = 'fatoora:replay-failed
                            {--dry-run : Show what would be replayed without executing}
                            {--force : Force replay even if some operations might be stale}';

    protected $description = 'Replay ZATCA operations that failed due to temporary outages';

    public function handle(FallbackHandler $handler): int
    {
        $pendingCount = $handler->getPendingReplayCount();

        if ($pendingCount === 0) {
            $this->info('No pending operations to replay.');
            return self::SUCCESS;
        }

        $this->info("Found {$pendingCount} pending operations.");

        if ($this->option('dry-run')) {
            $this->warn('Dry run mode - no operations will be replayed.');
            $this->showPendingOperations();
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Do you want to replay these operations?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $this->info('Replaying operations...');

        $results = $handler->replayStoredOperations();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total', $results['total']],
                ['Replayed', $results['replayed']],
                ['Failed', $results['failed']],
            ]
        );

        if ($results['failed'] > 0) {
            $this->warn("Some operations failed to replay. Check logs for details.");
            return self::FAILURE;
        }

        $this->info('All operations replayed successfully.');
        return self::SUCCESS;
    }

    private function showPendingOperations(): void
    {
        $replayPath = storage_path('logs/replay');
        if (!is_dir($replayPath)) {
            return;
        }

        $files = glob($replayPath . '/replay_*.json');
        $operations = [];

        foreach ($files as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);
                if (!($data['replayed'] ?? false)) {
                    $operations[] = [
                        'type' => $data['operation_type'] ?? 'unknown',
                        'created' => $data['created_at'] ?? 'unknown',
                        'file' => basename($file),
                    ];
                }
            } catch (\Throwable $e) {
                $operations[] = [
                    'type' => 'error',
                    'created' => 'parse failed',
                    'file' => basename($file),
                ];
            }
        }

        $this->table(['Type', 'Created At', 'File'], $operations);
    }
}
