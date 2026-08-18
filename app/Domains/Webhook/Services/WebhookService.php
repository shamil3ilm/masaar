<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook delivery service.
 *
 * Handles dispatching webhook notifications to subscribed endpoints.
 * Supports async delivery via Laravel queues.
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

            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $success = $response->successful();

            // Log the delivery
            WebhookLog::create([
                'webhook_id' => $webhook->id,
                'event' => $event,
                'payload' => $body,
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 1000),
                'duration_ms' => $duration,
                'success' => $success,
            ]);

            if ($success) {
                $webhook->recordSuccess();
            } else {
                $webhook->recordFailure();
            }

            return $success;
        } catch (\Exception $e) {
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            WebhookLog::create([
                'webhook_id' => $webhook->id,
                'event' => $event,
                'payload' => $body,
                'response_status' => 0,
                'response_body' => $e->getMessage(),
                'duration_ms' => $duration,
                'success' => false,
            ]);

            $webhook->recordFailure();

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
     * Create a new webhook subscription.
     */
    public function create(string $organizationId, string $url, array $events): Webhook
    {
        return Webhook::create([
            'org_id' => $organizationId,
            'url' => $url,
            'secret' => Str::random(64),
            'events' => $events,
            'is_active' => true,
            'failure_count' => 0,
        ]);
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
}
