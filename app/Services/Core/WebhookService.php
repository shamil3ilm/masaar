<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Jobs\DispatchWebhookJob;
use App\Models\Core\Webhook;
use App\Models\Core\WebhookDelivery;
use App\Models\Core\WebhookEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookService
{
    /**
     * Dispatch webhooks for an event.
     */
    public function dispatch(
        int $organizationId,
        string $eventType,
        array $data,
        ?string $resourceType = null,
        ?string $resourceId = null,
        bool $async = true
    ): int {
        // Record the event
        $event = WebhookEvent::create([
            'organization_id' => $organizationId,
            'event_type' => $eventType,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'data' => $data,
        ]);

        // Find active webhooks subscribed to this event
        $webhooks = Webhook::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($webhook) => $webhook->subscribesTo($eventType));

        if ($webhooks->isEmpty()) {
            return 0;
        }

        // Update event with webhook count
        $event->update(['webhooks_triggered' => $webhooks->count()]);

        // Create delivery records and dispatch
        foreach ($webhooks as $webhook) {
            $payload = $this->buildPayload($eventType, $data, $event->uuid);

            $delivery = WebhookDelivery::create([
                'webhook_id' => $webhook->id,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            if ($async) {
                DispatchWebhookJob::dispatch($delivery->id);
            } else {
                $this->sendWebhook($delivery);
            }
        }

        return $webhooks->count();
    }

    /**
     * Dispatch webhook for a model event.
     */
    public function dispatchForModel(
        Model $model,
        string $action,
        ?array $additionalData = null,
        bool $async = true
    ): int {
        $resourceType = class_basename($model);
        $eventType = Str::snake($resourceType) . '.' . $action;

        $data = array_merge(
            $this->transformModel($model),
            $additionalData ?? []
        );

        return $this->dispatch(
            $model->organization_id,
            $eventType,
            $data,
            $resourceType,
            (string) $model->getKey(),
            $async
        );
    }

    /**
     * Send a webhook delivery.
     */
    public function sendWebhook(WebhookDelivery $delivery): void
    {
        $webhook = $delivery->webhook;
        $payload = json_encode($delivery->payload);

        $headers = array_merge([
            'Content-Type' => $webhook->content_type,
            'X-Webhook-Id' => $webhook->uuid,
            'X-Webhook-Event' => $delivery->event_type,
            'X-Webhook-Delivery' => $delivery->uuid,
            'X-Webhook-Signature' => $webhook->generateSignature($payload),
            'X-Webhook-Timestamp' => (string) now()->timestamp,
        ], $webhook->headers ?? []);

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($webhook->timeout_seconds)
                ->withBody($payload, $webhook->content_type)
                ->post($webhook->url);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $delivery->markAsSuccess(
                    $response->status(),
                    $response->body(),
                    $response->headers(),
                    $durationMs
                );
            } else {
                $delivery->markAsFailed(
                    "HTTP {$response->status()}: " . $response->reason(),
                    $response->status(),
                    $response->body(),
                    $durationMs
                );
            }
        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $delivery->markAsFailed(
                $e->getMessage(),
                null,
                null,
                $durationMs
            );
        }
    }

    /**
     * Retry a failed delivery.
     */
    public function retryDelivery(WebhookDelivery $delivery): void
    {
        if (!$delivery->shouldRetry()) {
            return;
        }

        $delivery->update([
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempt' => $delivery->attempt + 1,
        ]);

        DispatchWebhookJob::dispatch($delivery->id);
    }

    /**
     * Retry all pending deliveries.
     */
    public function retryPendingDeliveries(): int
    {
        $deliveries = WebhookDelivery::where('status', WebhookDelivery::STATUS_PENDING)
            ->where('next_retry_at', '<=', now())
            ->limit(100)
            ->get();

        foreach ($deliveries as $delivery) {
            DispatchWebhookJob::dispatch($delivery->id);
        }

        return $deliveries->count();
    }

    /**
     * Create a webhook.
     */
    public function create(
        int $organizationId,
        User $user,
        string $name,
        string $url,
        array $events,
        array $options = []
    ): Webhook {
        return Webhook::create([
            'organization_id' => $organizationId,
            'created_by' => $user->id,
            'name' => $name,
            'url' => $url,
            'events' => $events,
            'headers' => $options['headers'] ?? null,
            'is_active' => $options['is_active'] ?? true,
            'retry_count' => $options['retry_count'] ?? 3,
            'timeout_seconds' => $options['timeout_seconds'] ?? 30,
            'content_type' => $options['content_type'] ?? 'application/json',
        ]);
    }

    /**
     * Update a webhook.
     */
    public function update(Webhook $webhook, array $data): Webhook
    {
        $webhook->update($data);
        return $webhook->fresh();
    }

    /**
     * Delete a webhook.
     */
    public function delete(Webhook $webhook): void
    {
        $webhook->delete();
    }

    /**
     * Test a webhook endpoint.
     */
    public function test(Webhook $webhook): array
    {
        $testPayload = $this->buildPayload('webhook.test', [
            'message' => 'This is a test webhook delivery',
            'webhook_id' => $webhook->uuid,
            'webhook_name' => $webhook->name,
            'test_timestamp' => now()->toIso8601String(),
        ], Str::uuid()->toString());

        $payload = json_encode($testPayload);

        $headers = array_merge([
            'Content-Type' => $webhook->content_type,
            'X-Webhook-Id' => $webhook->uuid,
            'X-Webhook-Event' => 'webhook.test',
            'X-Webhook-Delivery' => Str::uuid()->toString(),
            'X-Webhook-Signature' => $webhook->generateSignature($payload),
            'X-Webhook-Timestamp' => (string) now()->timestamp,
        ], $webhook->headers ?? []);

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($webhook->timeout_seconds)
                ->withBody($payload, $webhook->content_type)
                ->post($webhook->url);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'duration_ms' => $durationMs,
                'response_body' => substr($response->body(), 0, 1000),
            ];
        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ];
        }
    }

    /**
     * Get webhooks for organization.
     */
    public function getWebhooks(int $organizationId): Collection
    {
        return Webhook::where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get delivery history for a webhook.
     */
    public function getDeliveryHistory(Webhook $webhook, int $limit = 50): Collection
    {
        return $webhook->deliveries()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get event history for organization.
     */
    public function getEventHistory(int $organizationId, int $limit = 50): Collection
    {
        return WebhookEvent::where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Cleanup old webhook events and deliveries.
     */
    public function cleanup(int $days = 30): int
    {
        $cutoff = now()->subDays($days);

        // Delete old events
        $eventsDeleted = WebhookEvent::where('created_at', '<', $cutoff)->delete();

        // Delete old successful deliveries
        $deliveriesDeleted = WebhookDelivery::where('created_at', '<', $cutoff)
            ->where('status', WebhookDelivery::STATUS_SUCCESS)
            ->delete();

        return $eventsDeleted + $deliveriesDeleted;
    }

    // ==================== Protected Methods ====================

    protected function buildPayload(string $eventType, array $data, string $eventId): array
    {
        return [
            'event' => $eventType,
            'event_id' => $eventId,
            'created_at' => now()->toIso8601String(),
            'data' => $data,
        ];
    }

    protected function transformModel(Model $model): array
    {
        // Get model attributes with any custom serialization
        if (method_exists($model, 'toWebhookArray')) {
            return $model->toWebhookArray();
        }

        // Default: use toArray but hide sensitive fields
        $data = $model->makeHidden(['password', 'secret', 'api_key', 'remember_token'])->toArray();

        return $data;
    }
}
