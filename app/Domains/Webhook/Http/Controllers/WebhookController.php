<?php

namespace App\Domains\Webhook\Http\Controllers;

use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Services\WebhookService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook management API controller.
 *
 * Allows organizations to manage their webhook subscriptions
 * for receiving async notifications about invoice events.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly WebhookService $webhookService,
    ) {}

    /**
     * List webhooks for current organization.
     *
     * GET /api/webhooks
     */
    public function index(): JsonResponse
    {
        $webhooks = Webhook::where('organization_id', $this->tenant->getOrganizationId())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Webhook $w) => [
                'id' => $w->id,
                'url' => $w->url,
                'events' => $w->events,
                'is_active' => $w->is_active,
                'failure_count' => $w->failure_count,
                'last_triggered_at' => $w->last_triggered_at?->toISOString(),
                'created_at' => $w->created_at->toISOString(),
            ]);

        return ApiResponse::success(['webhooks' => $webhooks]);
    }

    /**
     * Create a new webhook.
     *
     * POST /api/webhooks
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', WebhookService::EVENTS) . ',*'],
        ]);

        $webhook = $this->webhookService->create(
            $this->tenant->getOrganizationId(),
            $request->url,
            $request->events
        );

        return ApiResponse::created([
            'webhook' => [
                'id' => $webhook->id,
                'url' => $webhook->url,
                'secret' => $webhook->secret, // Only shown once at creation
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
            ],
            'message' => 'Webhook created. Save the secret - it will not be shown again.',
        ]);
    }

    /**
     * Get webhook details.
     *
     * GET /api/webhooks/{id}
     */
    public function show(string $id): JsonResponse
    {
        $webhook = $this->getWebhook($id);

        return ApiResponse::success([
            'webhook' => [
                'id' => $webhook->id,
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
                'failure_count' => $webhook->failure_count,
                'last_triggered_at' => $webhook->last_triggered_at?->toISOString(),
                'created_at' => $webhook->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * Update webhook.
     *
     * PUT /api/webhooks/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $webhook = $this->getWebhook($id);

        $request->validate([
            'url' => ['sometimes', 'url', 'max:500'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', WebhookService::EVENTS) . ',*'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $webhook->update($request->only(['url', 'events', 'is_active']));

        // Reset failure count when re-enabling
        if ($request->is_active === true) {
            $webhook->update(['failure_count' => 0]);
        }

        return ApiResponse::success([
            'webhook' => [
                'id' => $webhook->id,
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
            ],
        ], 'Webhook updated');
    }

    /**
     * Delete webhook.
     *
     * DELETE /api/webhooks/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $webhook = $this->getWebhook($id);
        $webhook->delete();

        return ApiResponse::success(null, 'Webhook deleted');
    }

    /**
     * Test webhook delivery.
     *
     * POST /api/webhooks/{id}/test
     */
    public function test(string $id): JsonResponse
    {
        $webhook = $this->getWebhook($id);

        $success = $this->webhookService->test($webhook);

        if ($success) {
            return ApiResponse::success(null, 'Test webhook delivered successfully');
        }

        return ApiResponse::error('Test webhook delivery failed', 422);
    }

    /**
     * List available webhook events.
     *
     * GET /api/webhooks/events
     */
    public function events(): JsonResponse
    {
        return ApiResponse::success([
            'events' => WebhookService::EVENTS,
        ]);
    }

    /**
     * Get webhook logs.
     *
     * GET /api/webhooks/{id}/logs
     */
    public function logs(Request $request, string $id): JsonResponse
    {
        $webhook = $this->getWebhook($id);

        $logs = $webhook->logs()
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return ApiResponse::paginated($logs);
    }

    /**
     * Rotate webhook secret.
     *
     * POST /api/webhooks/{id}/rotate-secret
     */
    public function rotateSecret(string $id): JsonResponse
    {
        $webhook = $this->getWebhook($id);

        $newSecret = \Illuminate\Support\Str::random(64);
        $webhook->update(['secret' => $newSecret]);

        return ApiResponse::success([
            'secret' => $newSecret,
            'message' => 'Secret rotated. Save the new secret - it will not be shown again.',
        ]);
    }

    /**
     * Get webhook scoped to current organization.
     */
    private function getWebhook(string $id): Webhook
    {
        return Webhook::where('organization_id', $this->tenant->getOrganizationId())
            ->findOrFail($id);
    }
}
