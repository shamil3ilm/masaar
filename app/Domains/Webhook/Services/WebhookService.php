<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Webhook\DTOs\DeliveryOutcome;
use App\Domains\Webhook\Jobs\DeliverWebhook;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use App\Support\Brand;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook delivery service.
 *
 * Handles dispatching webhook notifications to subscribed endpoints.
 * Supports async delivery via Laravel queues. Managing the subscriptions
 * themselves is Subscriptions'.
 */
class WebhookService
{
    /**
     * The events an endpoint may subscribe to: each one something the
     * platform sends. invoice.warning (accepted by the authority with
     * warnings) and invoice.failed (filing failed) were sent and could not
     * be subscribed to, so only a "*" endpoint received them. invoice.created
     * and invoice.updated could be subscribed to and were never sent; a
     * draft is not a document anyone acts on, and the issued, filed and
     * answered events cover every invoice that is, so they are no longer
     * offered.
     */
    public const EVENTS = [
        'invoice.issued',
        'invoice.submitted',
        'invoice.cleared',
        'invoice.reported',
        'invoice.warning',
        'invoice.rejected',
        'invoice.failed',
        'onboarding.ccsid_obtained',
        'onboarding.compliance_passed',
        'onboarding.pcsid_obtained',
        'certificate.expiring',
        // A Peppol e-invoice's delivery and its report to the authority,
        // each confirmed on its own (StatusAnnouncer), and one received.
        'e_invoice.sent',
        'e_invoice.delivered',
        'e_invoice.rejected',
        'e_invoice.failed',
        'e_invoice.reported',
        'e_invoice.report_failed',
        'e_invoice.received',
        // A schedule's invoice for a period made, and a run that failed
        // (ScheduleAlerts).
        'schedule.run_created',
        'schedule.failed',
    ];

    /**
     * Queue the event for every endpoint subscribed to it.
     *
     * Queued, not sent: this runs inside issuance and submission, and a
     * customer's endpoint taking thirty seconds to answer held those up for
     * thirty seconds each. One job per endpoint, so one failing receiver is
     * retried without repeating the event to the others.
     *
     * @param  string  $organizationId  Organization ID
     * @param  string  $event  Event name
     * @param  array  $payload  Event payload
     */
    public function dispatch(string $organizationId, string $event, array $payload): void
    {
        $webhooks = Webhook::where('org_id', $organizationId)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            if (! $webhook->isSubscribedTo($event)) {
                continue;
            }

            // After commit: an event raised inside a transaction that rolls
            // back never happened, and must not be announced. And each push
            // on its own, so a queue failure on one endpoint neither stops the
            // others nor, by failing the caller, gets the ones already queued
            // queued again under new ids when the caller is retried.
            try {
                DeliverWebhook::dispatch($webhook->id, $event, $payload, Str::uuid()->toString())->afterCommit();
            } catch (\Throwable $e) {
                Log::error('Webhook delivery could not be queued', [
                    'webhook_id' => $webhook->id,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Deliver once, now, under a new delivery id: the test button and the
     * console's replay, which report the answer to the person who pressed it.
     */
    public function deliver(Webhook $webhook, string $event, array $payload): bool
    {
        return $this->attempt($webhook, $event, $payload, Str::uuid()->toString(), lastTry: true)->delivered;
    }

    /**
     * One attempt at a delivery.
     *
     * The HTTP call is made before anything is written, so no transaction is
     * held open while the receiver answers.
     *
     * A disabled endpoint is refused here rather than only by the caller.
     * The dispatch loop above selects on is_active, so for a long time that
     * looked like enough - until the console grew a replay button, which
     * calls this method directly with an endpoint it loaded by id. That was
     * the one path in the system able to post to an endpoint a customer had
     * switched off, and the endpoints a replay is aimed at are the failing
     * ones, which is exactly what recordFailure() disables after ten
     * consecutive failures.
     *
     * Nothing is recorded when it is refused. A log row means an attempt was
     * made, and reading one that was never sent is worse than reading none.
     *
     * Every attempt is logged, but only a delivery that has finished failing
     * counts against the endpoint's health: an attempt the queue will retry
     * is not yet a failure, and counting each one disabled an endpoint after
     * a few events during a short outage.
     */
    public function attempt(Webhook $webhook, string $event, array $payload, string $deliveryId, bool $lastTry): DeliveryOutcome
    {
        if (! $webhook->is_active) {
            return DeliveryOutcome::refused();
        }

        $timestamp = now()->toISOString();

        $body = [
            'id' => $deliveryId,
            'event' => $event,
            'timestamp' => $timestamp,
            'data' => $payload,
        ];

        // One signature per secret signing now, comma-separated: during a
        // rotation a receiver holding either the old or the new one
        // verifies (Webhook::signingSecrets()).
        $signature = implode(',', array_map(fn (string $secret): string => $this->generateSignature($body, $secret), $webhook->signingSecrets()));

        $startTime = microtime(true);

        // Checked here as well as when the URL was saved: an endpoint saved
        // before the check existed, or a name whose DNS has changed since,
        // would otherwise be called wherever it points now - the cloud
        // metadata service, Redis, the database. Recorded, so the tenant can
        // see why their endpoint stopped receiving.
        //
        // A name that did not resolve is retried like an unanswered request:
        // a resolver blip says nothing about the endpoint.
        [$refusal, $address, $transient] = SafeUrl::decide($webhook->url, 'security.webhook_hosts');

        if ($refusal !== null) {
            $this->record($webhook, [
                'event' => $event,
                'payload' => $body,
                'response_status' => 0,
                'response_body' => 'Not sent: '.$refusal,
                'duration_ms' => 0,
                'success' => false,
            ], countFailure: $lastTry || ! $transient);

            Log::warning('Webhook delivery refused', [
                'webhook_id' => $webhook->id,
                'reason' => $refusal,
            ]);

            return $transient ? DeliveryOutcome::unanswered() : DeliveryOutcome::refused();
        }

        // Only the request is in the try. Recording an answer that did arrive
        // and then failing to write it would otherwise log a second, failed
        // attempt, count it against the endpoint, and send it again.
        try {
            $response = Http::timeout(30)
                // A redirect would take the request somewhere the check above
                // never looked. A 3xx is an answer, and an unsuccessful one.
                ->withOptions(['allow_redirects' => false, ...$this->pinned($webhook->url, $address)])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-ID' => $deliveryId,
                    'X-Webhook-Event' => $event,
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Timestamp' => $timestamp,
                    'User-Agent' => Brand::userAgent('Webhook'),
                ])
                ->post($webhook->url, $body);
        } catch (\Exception $e) {
            $this->record($webhook, [
                'event' => $event,
                'payload' => $body,
                'response_status' => 0,
                'response_body' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'success' => false,
            ], countFailure: $lastTry);

            Log::warning('Webhook delivery failed', [
                'webhook_id' => $webhook->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return DeliveryOutcome::unanswered();
        }

        $outcome = DeliveryOutcome::forStatus($response->status());

        $this->record($webhook, [
            'event' => $event,
            'payload' => $body,
            'response_status' => $response->status(),
            'response_body' => Str::limit($response->body(), 1000),
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'success' => $outcome->delivered,
        ], countFailure: $lastTry || ! $outcome->retryable);

        return $outcome;
    }

    /**
     * Connect to the address SafeUrl approved, and only that one.
     *
     * Without it the client resolves the name again, and a name can answer
     * with a public address to the check and a private one to the connection.
     *
     * @return array<string, mixed>
     */
    private function pinned(string $url, ?string $address): array
    {
        if ($address === null) {
            return [];
        }

        $parts = parse_url($url);
        $port = $parts['port'] ?? 443;
        $host = $parts['host'] ?? '';
        $target = str_contains($address, ':') ? '['.$address.']' : $address;

        return ['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$target]]];
    }

    /**
     * Generate HMAC signature for webhook payload.
     */
    public function generateSignature(array $payload, string $secret): string
    {
        $json = json_encode($payload);

        return 'sha256='.hash_hmac('sha256', $json, $secret);
    }

    /**
     * Verify webhook signature.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Test webhook endpoint.
     */
    public function test(Webhook $webhook): bool
    {
        return $this->deliver($webhook, 'webhook.test', [
            'message' => 'This is a test webhook delivery',
            'webhook_id' => $webhook->id,
        ]);
    }

    /**
     * Log one delivery attempt and update the endpoint's health, together.
     *
     * The endpoint is re-read under lock: concurrent deliveries each add a
     * failure, and the one that reaches the limit has to count the others to
     * disable it. The read is past the tenant scope because a delivery can run
     * in a request acting for no tenant, and the endpoint was already chosen
     * for its organization by dispatch().
     *
     * @param  array{event: string, payload: array, response_status: int, response_body: string, duration_ms: int, success: bool}  $attempt
     * @param  bool  $countFailure  False for an attempt the queue will retry.
     */
    private function record(Webhook $webhook, array $attempt, bool $countFailure): void
    {
        DB::transaction(function () use ($webhook, $attempt, $countFailure): void {
            WebhookLog::create(['webhook_id' => $webhook->id, ...$attempt]);

            $endpoint = Webhook::acrossTenants()->lockForUpdate()->findOrFail($webhook->getKey());

            if ($attempt['success']) {
                $endpoint->recordSuccess();

                return;
            }

            if (! $countFailure) {
                return;
            }

            $endpoint->recordFailure();

            // Critical, so it reaches the alert channel: from here nothing is
            // sent to this endpoint until its owner turns it back on.
            if (! $endpoint->is_active) {
                Log::critical('Webhook endpoint disabled after repeated failed deliveries', [
                    'webhook_id' => $endpoint->id,
                    'org_id' => $endpoint->org_id,
                    'failure_count' => $endpoint->failure_count,
                ]);
            }
        });
    }
}
