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
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

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
