<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| What cannot be rebuilt: the database (every tenant's invoices, hash chains
| and audit trail) and the credential store (device keys and CSIDs). The code
| is in git and the image registry, so it is not backed up.
|
| Neither is any key. The credential files are encrypted under
| ZATCA_CREDENTIAL_KEY and the database holds values encrypted under APP_KEY;
| both keys are kept apart from these archives, or a stolen archive would carry
| its own keys. A restore needs them. See docs/BACKUP-AND-RESTORE.md.
|
| Scheduled in routes/console.php; failures page through AlertOnBackupTrouble.
|
*/

$disks = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_DISKS', 'backups')))));

return [

    'backup' => [
        'name' => env('APP_NAME', 'Masaar'),

        'source' => [
            'files' => [
                /*
                 * The credential store. CredentialStore writes under zatca/ on
                 * the disk named by ZATCA_CREDENTIAL_DISK, 'local' by default,
                 * whose root is storage/app/private. On shared storage instead,
                 * that storage's own backup covers it and this path is skipped
                 * with a warning.
                 */
                'include' => [
                    storage_path('app/private/zatca'),
                ],

                'exclude' => [],

                'follow_links' => false,

                'ignore_unreadable_directories' => false,

                // Archived as zatca/..., so a restore copies it back as is.
                'relative_path' => storage_path('app/private'),
            ],

            // Dump options (single transaction, SSL) are on the connection, in
            // config/database.php.
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        'database_dump_compressor' => GzipCompressor::class,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,

            'compression_level' => 9,

            'filename_prefix' => '',

            /*
             * Offsite: the object store the 'backups' disk names, configured
             * by BACKUP_STORAGE_* (config/filesystems.php). Comma-separated
             * to write to more than one.
             */
            'disks' => $disks,

            // A backup that reached only some destinations is a failure.
            'continue_on_failure' => false,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * Required. Left empty, the package would skip encryption entirely;
         * RefuseUnencryptedBackup stops the run instead.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        // Stated, not 'default': 'default' quietly settles for less when
        // AES-256 is unavailable.
        'encryption' => 'aes256',

        // Open the zip after writing it. An archive that cannot be read is
        // found tonight rather than on the day it is needed.
        'verify_backup' => true,

        // One retry, for a bucket that blinked.
        'tries' => 2,

        'retry_delay' => 60,
    ],

    /*
     * The package's notifications mail a fixed address, so each is switched
     * off here; AlertOnBackupTrouble logs failures as critical instead, which
     * reaches the alerts channel with everything else that pages.
     */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => [],
            UnhealthyBackupWasFoundNotification::class => [],
            CleanupHasFailedNotification::class => [],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Masaar'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],

        'webhook' => [
            'url' => '',
        ],
    ],

    'log_channel' => null,

    /*
     * backup:monitor raises UnhealthyBackupWasFound when the newest archive is
     * more than a day old - which is what a scheduler that stopped running, or
     * a run that never started, looks like. A failed run is caught sooner, by
     * BackupHasFailed.
     *
     * Storage is watched rather than pruned to: retention is a legal minimum,
     * so nothing deletes an archive to make room, and a bucket with a quota
     * would otherwise fill and fail one night. The default alerts at 16 GB,
     * sized for a 20 GB quota; set it under whatever the bucket allows.
     */
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'Masaar'),
            'disks' => $disks,
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => (int) env('BACKUP_ALERT_AT_MEGABYTES', 16384),
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        /*
         * Saudi VAT records must be kept six years. The database is the record
         * and these are copies of it, but a copy from each year is what makes
         * an old state recoverable, so the yearly tier covers the period.
         */
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,

            'keep_daily_backups_for_days' => 30,

            'keep_weekly_backups_for_weeks' => 12,

            'keep_monthly_backups_for_months' => 12,

            'keep_yearly_backups_for_years' => 6,

            // Never prune to fit a size: that would delete the oldest, which
            // are the ones retention exists to keep.
            'delete_oldest_backups_when_using_more_megabytes_than' => null,
        ],

        'tries' => 1,

        'retry_delay' => 0,
    ],

];
