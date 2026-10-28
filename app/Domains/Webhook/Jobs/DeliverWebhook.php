<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Jobs;

use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One event, to one endpoint, until it is answered.
 *
 * Deliveries were made in a loop over every endpoint, inside the event
 * listener. A failed one was logged and the loop moved on, so an endpoint
 * that was down for a minute simply missed the event; and when the listener
 * itself was retried, every endpoint that had already received it got it
 * again under a new id, which a receiver cannot recognise as a repeat.
 *
 * Now each endpoint gets its own job, and the delivery id is fixed when it is
 * queued, so every attempt carries the same X-Webhook-ID and a receiver can
 * discard the second copy of anything it already processed. The timestamp is
 * fresh on each attempt: it is what a receiver checks for staleness.
 *
 * Retried with release() rather than an exception. An unanswered delivery is
 * expected, not an error, and a worker logs every exception it catches.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /**
     * The request is capped at thirty seconds, but resolving the endpoint's
     * name before it is not, so the job carries its own limit.
     */
    public int $timeout = 60;

    /**
     * Seconds before each retry: about forty minutes in all, enough to ride
     * out a deploy or a restart at the receiver.
     *
     * @var list<int>
     */
    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $webhookId,
        public string $event,
        public array $payload,
        public string $deliveryId,
    ) {
        // Off the submissions queue, so a slow endpoint cannot delay a filing.
        $this->onQueue((string) config('fatoora.queue.webhooks_queue', 'webhooks'));

        $connection = (string) config('fatoora.queue.connection');

        if ($connection !== '') {
            $this->onConnection($connection);
        }
    }

    public function handle(WebhookService $webhooks): void
    {
        // Past the tenant scope: a worker acts for no tenant, and the endpoint
        // was chosen for its organization when the job was queued.
        $webhook = Webhook::acrossTenants()->find($this->webhookId);

        if ($webhook === null) {
            return;
        }

        $lastTry = $this->attempts() >= $this->tries;

        $outcome = $webhooks->attempt($webhook, $this->event, $this->payload, $this->deliveryId, $lastTry);

        if ($outcome->retryable && ! $lastTry) {
            $this->release($this->backoff[$this->attempts() - 1] ?? end($this->backoff));
        }
    }
}
