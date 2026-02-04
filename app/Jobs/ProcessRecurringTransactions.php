<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Core\RecurringTransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRecurringTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600; // 10 minutes

    public function __construct()
    {
        $this->onQueue('recurring');
    }

    public function handle(RecurringTransactionService $service): void
    {
        Log::info('Starting recurring transactions processing');

        $results = $service->processDueProfiles();

        Log::info('Recurring transactions processing completed', $results);

        if (!empty($results['errors'])) {
            Log::warning('Some recurring profiles failed to process', [
                'errors' => $results['errors'],
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Recurring transactions job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
