<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Console;

use App\Domains\Licensing\Services\RequestLog;
use Illuminate\Console\Command;

/**
 * Remove each organization's API requests older than it keeps them
 * (integrations.request_log_days). Scheduled daily.
 */
class PruneRequestLog extends Command
{
    protected $signature = 'integrations:prune-request-log';

    protected $description = 'Remove API request log entries past each organization\'s keeping period';

    public function handle(RequestLog $requests): int
    {
        $removed = $requests->prune();

        $this->info("Removed {$removed} API request log entries.");

        return self::SUCCESS;
    }
}
