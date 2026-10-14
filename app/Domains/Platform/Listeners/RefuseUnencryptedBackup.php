<?php

declare(strict_types=1);

namespace App\Domains\Platform\Listeners;

use RuntimeException;
use Spatie\Backup\Events\DumpingDatabase;

/**
 * No database dump is written without an archive password.
 *
 * The dump holds every tenant's invoices and users, and the archive beside it
 * the device keys. spatie/laravel-backup reads an empty password as "do not
 * encrypt" and carries on, so a deployment that forgot BACKUP_ARCHIVE_PASSWORD
 * would upload all of it in the clear, nightly, and report success.
 *
 * Refused at the dump rather than at boot: a boot guard would stop every
 * deployment and CI run that has no reason to back anything up. Thrown here,
 * the package fails the run and raises BackupHasFailed, which pages.
 */
class RefuseUnencryptedBackup
{
    public function handle(DumpingDatabase $event): void
    {
        if ((string) config('backup.backup.password') === '') {
            throw new RuntimeException(
                'Refusing to back up: BACKUP_ARCHIVE_PASSWORD is not set, so the archive would not be encrypted.'
            );
        }
    }
}
