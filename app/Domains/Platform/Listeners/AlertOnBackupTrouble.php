<?php

declare(strict_types=1);

namespace App\Domains\Platform\Listeners;

use Illuminate\Support\Facades\Log;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * Backup trouble reaches the alert channel.
 *
 * The package's own notifications mail a fixed address and are switched off
 * in config/backup.php. Logged critical instead, a failed or stale backup goes
 * where every other page goes: the alerts channel, and its chat webhook once
 * one is configured.
 *
 * A failed cleanup is an error, not a page: the newest backup is intact and
 * the cost is storage, which the next night's cleanup will usually recover.
 */
class AlertOnBackupTrouble
{
    public function handle(BackupHasFailed|UnhealthyBackupWasFound|CleanupHasFailed $event): void
    {
        match (true) {
            $event instanceof BackupHasFailed => Log::critical(
                'Backup failed; the platform has no fresh copy of its invoices and credentials',
                ['disk' => $event->diskName, 'error' => $event->exception->getMessage()]
            ),
            $event instanceof UnhealthyBackupWasFound => Log::critical(
                'Backup is unhealthy: '.$event->failureMessages->pluck('message')->implode('; '),
                ['disk' => $event->diskName, 'backup' => $event->backupName]
            ),
            default => Log::error(
                'Backup cleanup failed; old archives were not pruned',
                ['disk' => $event->diskName, 'error' => $event->exception->getMessage()]
            ),
        };
    }
}
