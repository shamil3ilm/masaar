<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Zatca\Services\OfflineQueueManager;
use Illuminate\Console\Command;

/**
 * Clean up old items from the offline queue.
 *
 * Removes completed, failed, and cancelled items that are
 * older than the retention period to prevent table bloat.
 */
class CleanupOfflineQueue extends Command
{
    protected $signature = 'compliance:cleanup-offline-queue
                            {--days=7 : Remove items older than this many days}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Clean up old items from the ZATCA offline queue';

    public function __construct(
        private readonly OfflineQueueManager $queueManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $this->info("Cleaning up offline queue items older than {$days} days...");

        if ($dryRun) {
            $this->warn('DRY RUN - no items will be deleted');
        }

        $deleted = $dryRun ? 0 : $this->queueManager->cleanup($days);

        $this->info("Cleaned up {$deleted} items from offline queue.");

        return Command::SUCCESS;
    }
}
