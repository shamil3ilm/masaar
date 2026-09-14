<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
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
     * Available webhook events.
     */
    public const EVENTS = [
        'invoice.created',
        'invoice.updated',
        'invoice.issued',
        'invoice.submitted',
        'invoice.cleared',
        'invoice.reported',
        'invoice.rejected',
        'onboarding.ccsid_obtained',
        'onboarding.compliance_passed',
        'onboarding.pcsid_obtained',
    ];

    /**
     * Dispatch webhook for an event.
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
            if ($webhook->isSubscribedTo($event)) {
                $this->deliver($webhook, $event, $payload);
            }
        }
    }

    /**
     * Deliver webhook to endpoint.
     *
     * The HTTP call is made before anything is written, so no transaction is
     * held open while the receiver answers.
     */
    public function deliver(Webhook $webhook, string $event, array $payload): bool
    {
        $deliveryId = Str::uuid()->toString();
        $timestamp = now()->toISOString();

        $body = [
            'id' => $deliveryId,
            'event' => $event,
            'timestamp' => $timestamp,
            'data' => $payload,
        ];

        // Generate signature
        $signature = $this->generateSignature($body, $webhook->secret);

        $startTime = microtime(true);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-ID' => $deliveryId,
                    'X-Webhook-Event' => $event,
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Timestamp' => $timestamp,
                    'User-Agent' => 'Masaar-Webhook/1.0',
                ])
                ->post($webhook->url, $body);

            $success = $response->successful();

            $this->record($webhook, [
                'event' => $event,
                'payload' => $body,
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 1000),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'success' => $success,
            ]);

            return $success;
        } catch (\Exception $e) {
            $this->record($webhook, [
                'event' => $event,
                'payload' => $body,
                'response_status' => 0,
                'response_body' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'success' => false,
            ]);

            Log::warning('Webhook delivery failed', [
                'webhook_id' => $webhook->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
     */
    private function record(Webhook $webhook, array $attempt): void
    {
        DB::transaction(function () use ($webhook, $attempt): void {
            WebhookLog::create(['webhook_id' => $webhook->id, ...$attempt]);

            $endpoint = Webhook::withoutTenantScope(
                fn () => Webhook::query()->lockForUpdate()->findOrFail($webhook->getKey())
            );

            if ($attempt['success']) {
                $endpoint->recordSuccess();
            } else {
                $endpoint->recordFailure();
            }
        });
    }
}
