<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Core\WebhookService;
use Illuminate\Support\Str;

/**
 * Trait for automatically dispatching webhooks on model events.
 *
 * Usage:
 * - Add `use DispatchesWebhooks;` to your model
 * - Optionally override $webhookEvents to customize which events trigger webhooks
 * - Optionally override toWebhookArray() to customize the payload
 */
trait DispatchesWebhooks
{
    /**
     * Events that should trigger webhooks.
     * Override this property in your model to customize.
     */
    protected static array $webhookEvents = ['created', 'updated', 'deleted'];

    /**
     * Boot the trait.
     */
    protected static function bootDispatchesWebhooks(): void
    {
        foreach (static::$webhookEvents as $event) {
            static::$event(function ($model) use ($event) {
                static::dispatchWebhookForEvent($model, $event);
            });
        }
    }

    /**
     * Dispatch webhook for a model event.
     */
    protected static function dispatchWebhookForEvent($model, string $event): void
    {
        // Skip if model doesn't have organization_id
        if (!isset($model->organization_id)) {
            return;
        }

        // Skip during tests unless explicitly enabled
        if (app()->environment('testing') && !config('webhooks.dispatch_in_tests', false)) {
            return;
        }

        try {
            $webhookService = app(WebhookService::class);

            $webhookService->dispatchForModel(
                $model,
                $event,
                $model->getAdditionalWebhookData($event)
            );
        } catch (\Exception $e) {
            // Log but don't fail the operation
            \Log::warning("Failed to dispatch webhook: {$e->getMessage()}", [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Get the event type for webhooks.
     * Override this to customize the event type format.
     */
    public function getWebhookEventType(string $action): string
    {
        $resourceType = Str::snake(class_basename($this));
        return "{$resourceType}.{$action}";
    }

    /**
     * Get the data to include in webhook payload.
     * Override this to customize the payload.
     */
    public function toWebhookArray(): array
    {
        return $this->makeHidden([
            'password',
            'secret',
            'api_key',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
        ])->toArray();
    }

    /**
     * Get additional data to include in webhook payload for specific events.
     * Override this to add event-specific data.
     */
    public function getAdditionalWebhookData(string $event): array
    {
        return [];
    }

    /**
     * Manually dispatch a webhook for this model.
     */
    public function dispatchWebhook(string $action, ?array $additionalData = null): int
    {
        if (!isset($this->organization_id)) {
            return 0;
        }

        $webhookService = app(WebhookService::class);

        return $webhookService->dispatchForModel(
            $this,
            $action,
            $additionalData
        );
    }
}
