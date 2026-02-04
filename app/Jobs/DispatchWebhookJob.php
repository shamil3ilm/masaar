<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Core\WebhookDelivery;
use App\Services\Core\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        protected int $deliveryId
    ) {}

    public function handle(WebhookService $webhookService): void
    {
        $delivery = WebhookDelivery::with('webhook')->find($this->deliveryId);

        if (!$delivery) {
            return;
        }

        // Skip if webhook is no longer active
        if (!$delivery->webhook->is_active) {
            $delivery->markAsFailed('Webhook is disabled');
            return;
        }

        $webhookService->sendWebhook($delivery);
    }

    public function failed(\Throwable $exception): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if ($delivery) {
            $delivery->markAsFailed('Job failed: ' . $exception->getMessage());
        }
    }
}
