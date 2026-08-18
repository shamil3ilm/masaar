<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Listeners;

use App\Domains\Compliance\Fatoora\Events\BaseInvoiceEvent;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener that dispatches webhooks for invoice events.
 *
 * This listener is queued to prevent webhook delivery from
 * blocking the main submission flow.
 */
class DispatchInvoiceWebhook implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The queue this job should run on.
     */
    public string $queue = 'webhooks';

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly WebhookService $webhookService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(BaseInvoiceEvent $event): void
    {
        $organizationId = $event->getOrganizationId();
        $webhookEventName = $event->getWebhookEventName();
        $payload = $event->getWebhookPayload();

        Log::info('Dispatching invoice webhook', [
            'org_id' => $organizationId,
            'event' => $webhookEventName,
            'invoice_id' => $event->getInvoiceId(),
            'state' => $event->getState(),
        ]);

        try {
            $this->webhookService->dispatch(
                organizationId: $organizationId,
                event: $webhookEventName,
                payload: $payload
            );

            Log::info('Invoice webhook dispatched successfully', [
                'org_id' => $organizationId,
                'event' => $webhookEventName,
                'invoice_id' => $event->getInvoiceId(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch invoice webhook', [
                'org_id' => $organizationId,
                'event' => $webhookEventName,
                'invoice_id' => $event->getInvoiceId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(BaseInvoiceEvent $event, \Throwable $exception): void
    {
        Log::error('Invoice webhook dispatch permanently failed', [
            'org_id' => $event->getOrganizationId(),
            'event' => $event->getWebhookEventName(),
            'invoice_id' => $event->getInvoiceId(),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Determine whether the listener should be queued.
     */
    public function shouldQueue(BaseInvoiceEvent $event): bool
    {
        // Always queue - webhooks should never block submission
        return true;
    }
}
