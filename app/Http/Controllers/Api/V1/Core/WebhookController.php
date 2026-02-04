<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\Webhook;
use App\Services\Core\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookService $webhookService
    ) {}

    /**
     * Get available webhook events.
     */
    public function events(): JsonResponse
    {
        return response()->json([
            'data' => [
                'events' => Webhook::EVENTS,
                'events_by_module' => Webhook::getEventsByModule(),
            ],
        ]);
    }

    /**
     * List organization webhooks.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $webhooks = $this->webhookService->getWebhooks($user->organization_id);

        return response()->json([
            'data' => $webhooks->map(fn ($webhook) => [
                'id' => $webhook->id,
                'uuid' => $webhook->uuid,
                'name' => $webhook->name,
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
                'success_count' => $webhook->success_count,
                'failure_count' => $webhook->failure_count,
                'success_rate' => $webhook->success_rate,
                'last_triggered_at' => $webhook->last_triggered_at?->toIso8601String(),
                'last_success_at' => $webhook->last_success_at?->toIso8601String(),
                'last_failure_at' => $webhook->last_failure_at?->toIso8601String(),
                'created_at' => $webhook->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Get webhook details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $webhook->id,
                'uuid' => $webhook->uuid,
                'name' => $webhook->name,
                'url' => $webhook->url,
                'secret_masked' => $webhook->masked_secret,
                'events' => $webhook->events,
                'headers' => $webhook->headers,
                'is_active' => $webhook->is_active,
                'retry_count' => $webhook->retry_count,
                'timeout_seconds' => $webhook->timeout_seconds,
                'content_type' => $webhook->content_type,
                'success_count' => $webhook->success_count,
                'failure_count' => $webhook->failure_count,
                'success_rate' => $webhook->success_rate,
                'last_triggered_at' => $webhook->last_triggered_at?->toIso8601String(),
                'last_success_at' => $webhook->last_success_at?->toIso8601String(),
                'last_failure_at' => $webhook->last_failure_at?->toIso8601String(),
                'created_at' => $webhook->created_at->toIso8601String(),
                'created_by' => $webhook->creator?->name,
            ],
        ]);
    }

    /**
     * Create a webhook.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'url' => 'required|url|max:500',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:*,' . implode(',', array_keys(Webhook::EVENTS)),
            'headers' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
            'retry_count' => 'sometimes|integer|min:0|max:10',
            'timeout_seconds' => 'sometimes|integer|min:5|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $webhook = $this->webhookService->create(
            $user->organization_id,
            $user,
            $request->get('name'),
            $request->get('url'),
            $request->get('events'),
            [
                'headers' => $request->get('headers'),
                'is_active' => $request->get('is_active', true),
                'retry_count' => $request->get('retry_count', 3),
                'timeout_seconds' => $request->get('timeout_seconds', 30),
            ]
        );

        return response()->json([
            'data' => [
                'id' => $webhook->id,
                'uuid' => $webhook->uuid,
                'name' => $webhook->name,
                'url' => $webhook->url,
                'secret' => $webhook->secret, // Only returned on creation
                'events' => $webhook->events,
            ],
            'message' => 'Webhook created successfully. Please save the secret securely.',
        ], 201);
    }

    /**
     * Update a webhook.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'url' => 'sometimes|url|max:500',
            'events' => 'sometimes|array|min:1',
            'events.*' => 'string|in:*,' . implode(',', array_keys(Webhook::EVENTS)),
            'headers' => 'sometimes|array|nullable',
            'is_active' => 'sometimes|boolean',
            'retry_count' => 'sometimes|integer|min:0|max:10',
            'timeout_seconds' => 'sometimes|integer|min:5|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $webhook = $this->webhookService->update($webhook, $request->only([
            'name', 'url', 'events', 'headers', 'is_active', 'retry_count', 'timeout_seconds',
        ]));

        return response()->json([
            'data' => [
                'id' => $webhook->id,
                'uuid' => $webhook->uuid,
                'name' => $webhook->name,
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
            ],
            'message' => 'Webhook updated successfully.',
        ]);
    }

    /**
     * Delete a webhook.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $this->webhookService->delete($webhook);

        return response()->json([
            'message' => 'Webhook deleted successfully.',
        ]);
    }

    /**
     * Test a webhook endpoint.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $result = $this->webhookService->test($webhook);

        return response()->json([
            'data' => $result,
            'message' => $result['success'] ? 'Webhook test successful!' : 'Webhook test failed.',
        ]);
    }

    /**
     * Regenerate webhook secret.
     */
    public function regenerateSecret(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $newSecret = $webhook->regenerateSecret();

        return response()->json([
            'data' => [
                'secret' => $newSecret,
            ],
            'message' => 'Webhook secret regenerated. Please update your integration.',
        ]);
    }

    /**
     * Toggle webhook active status.
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $webhook->update(['is_active' => !$webhook->is_active]);

        return response()->json([
            'data' => [
                'is_active' => $webhook->is_active,
            ],
            'message' => $webhook->is_active ? 'Webhook enabled.' : 'Webhook disabled.',
        ]);
    }

    /**
     * Get delivery history for a webhook.
     */
    public function deliveries(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $limit = min((int) $request->get('limit', 50), 100);

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $deliveries = $this->webhookService->getDeliveryHistory($webhook, $limit);

        return response()->json([
            'data' => $deliveries->map(fn ($d) => [
                'id' => $d->id,
                'uuid' => $d->uuid,
                'event_type' => $d->event_type,
                'status' => $d->status,
                'http_status' => $d->http_status,
                'duration_ms' => $d->duration_ms,
                'attempt' => $d->attempt,
                'error_message' => $d->error_message,
                'created_at' => $d->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Get delivery details.
     */
    public function deliveryDetails(Request $request, int $id, int $deliveryId): JsonResponse
    {
        $user = $request->user();

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $delivery = $webhook->deliveries()->findOrFail($deliveryId);

        return response()->json([
            'data' => [
                'id' => $delivery->id,
                'uuid' => $delivery->uuid,
                'event_type' => $delivery->event_type,
                'payload' => $delivery->payload,
                'status' => $delivery->status,
                'http_status' => $delivery->http_status,
                'response_body' => $delivery->response_body,
                'response_headers' => $delivery->response_headers,
                'duration_ms' => $delivery->duration_ms,
                'attempt' => $delivery->attempt,
                'error_message' => $delivery->error_message,
                'next_retry_at' => $delivery->next_retry_at?->toIso8601String(),
                'created_at' => $delivery->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Retry a failed delivery.
     */
    public function retryDelivery(Request $request, int $id, int $deliveryId): JsonResponse
    {
        $user = $request->user();

        $webhook = Webhook::where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $delivery = $webhook->deliveries()->findOrFail($deliveryId);

        if ($delivery->isSuccess()) {
            return response()->json(['error' => 'Cannot retry successful delivery'], 400);
        }

        $this->webhookService->retryDelivery($delivery);

        return response()->json([
            'message' => 'Delivery queued for retry.',
        ]);
    }

    /**
     * Get recent webhook events.
     */
    public function events_history(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min((int) $request->get('limit', 50), 100);

        $events = $this->webhookService->getEventHistory($user->organization_id, $limit);

        return response()->json([
            'data' => $events->map(fn ($e) => [
                'id' => $e->id,
                'uuid' => $e->uuid,
                'event_type' => $e->event_type,
                'resource_type' => $e->resource_type,
                'resource_id' => $e->resource_id,
                'webhooks_triggered' => $e->webhooks_triggered,
                'created_at' => $e->created_at->toIso8601String(),
            ]),
        ]);
    }
}
