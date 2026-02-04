<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupAuditLogs extends Command
{
    protected $signature = 'audit-logs:cleanup {--days=90 : Number of days to keep audit logs}';

    protected $description = 'Remove old audit log entries';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $deleted = DB::table('audit_logs')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Cleaned up {$deleted} audit log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
