<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Listeners;

use App\Domains\Webhook\Events\CertificateExpiring;
use App\Domains\Webhook\Services\WebhookService;

/**
 * Tells subscribed endpoints a certificate is running out.
 *
 * CheckCertificateExpiry has fired CertificateExpiring for a long time, and
 * the partner guide tells integrators to subscribe to certificate.expiring -
 * but nothing listened, and the event was not in WebhookService::EVENTS, so a
 * subscription to it was refused. The warning reached email and nothing else.
 *
 * Not queued itself: dispatch() only queues a job per endpoint.
 */
class DispatchCertificateWebhook
{
    public function __construct(
        private readonly WebhookService $webhooks,
    ) {}

    public function handle(CertificateExpiring $event): void
    {
        $this->webhooks->dispatch(
            $event->organizationId,
            $event->getWebhookEventName(),
            $event->getWebhookPayload(),
        );
    }
}
