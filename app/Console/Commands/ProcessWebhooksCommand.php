<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Core\WebhookService;
use Illuminate\Console\Command;

class ProcessWebhooksCommand extends Command
{
    protected $signature = 'webhooks:process
                            {--retry : Retry pending webhook deliveries}
                            {--cleanup : Cleanup old webhook events and deliveries}
                            {--days=30 : Days to keep webhook history}';

    protected $description = 'Process webhook retries and cleanup';

    public function handle(WebhookService $webhookService): int
    {
        if ($this->option('retry')) {
            $this->info('Retrying pending webhook deliveries...');
            $count = $webhookService->retryPendingDeliveries();
            $this->info("Dispatched {$count} pending deliveries for retry.");
        }

        if ($this->option('cleanup')) {
            $days = (int) $this->option('days');
            $this->info("Cleaning up webhook history older than {$days} days...");
            $count = $webhookService->cleanup($days);
            $this->info("Cleaned up {$count} old records.");
        }

        if (!$this->option('retry') && !$this->option('cleanup')) {
            // Default: do both
            $this->info('Processing webhooks...');

            $retryCount = $webhookService->retryPendingDeliveries();
            $this->info("Dispatched {$retryCount} pending deliveries for retry.");

            $cleanupCount = $webhookService->cleanup(30);
            $this->info("Cleaned up {$cleanupCount} old records.");
        }

        return Command::SUCCESS;
    }
}
