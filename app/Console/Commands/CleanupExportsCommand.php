<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Core\ExportService;
use Illuminate\Console\Command;

class CleanupExportsCommand extends Command
{
    protected $signature = 'exports:cleanup';

    protected $description = 'Clean up expired export files';

    public function handle(ExportService $exportService): int
    {
        $this->info('Cleaning up expired exports...');

        $count = $exportService->cleanupExpired();

        $this->info("Cleaned up {$count} expired export(s).");

        return Command::SUCCESS;
    }
}
