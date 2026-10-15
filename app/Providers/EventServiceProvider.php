<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Compliance\Fatoora\Events\InvoiceCleared;
use App\Domains\Compliance\Fatoora\Events\InvoiceFailed;
use App\Domains\Compliance\Fatoora\Events\InvoiceRejected;
use App\Domains\Compliance\Fatoora\Events\InvoiceReported;
use App\Domains\Compliance\Fatoora\Events\InvoiceSubmitted;
use App\Domains\Compliance\Fatoora\Events\InvoiceWarning;
use App\Domains\Compliance\Fatoora\Listeners\DispatchInvoiceWebhook;
use App\Domains\Platform\Listeners\AlertOnBackupTrouble;
use App\Domains\Platform\Listeners\RefuseUnencryptedBackup;
use App\Domains\Webhook\Events\CertificateExpiring;
use App\Domains\Webhook\Listeners\DispatchCertificateWebhook;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\DumpingDatabase;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * Event Service Provider.
 *
 * Registers all application events and their listeners.
 * Invoice events auto-dispatch webhooks for real-time compliance notifications.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Invoice state transition events - all dispatch webhooks
        InvoiceCleared::class => [
            DispatchInvoiceWebhook::class,
        ],
        InvoiceReported::class => [
            DispatchInvoiceWebhook::class,
        ],
        InvoiceRejected::class => [
            DispatchInvoiceWebhook::class,
        ],
        InvoiceWarning::class => [
            DispatchInvoiceWebhook::class,
        ],
        InvoiceFailed::class => [
            DispatchInvoiceWebhook::class,
        ],
        InvoiceSubmitted::class => [
            DispatchInvoiceWebhook::class,
        ],
        CertificateExpiring::class => [
            DispatchCertificateWebhook::class,
        ],

        // Backups: refuse to dump unencrypted, and page when one fails or goes stale.
        DumpingDatabase::class => [
            RefuseUnencryptedBackup::class,
        ],
        BackupHasFailed::class => [
            AlertOnBackupTrouble::class,
        ],
        UnhealthyBackupWasFound::class => [
            AlertOnBackupTrouble::class,
        ],
        CleanupHasFailed::class => [
            AlertOnBackupTrouble::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
