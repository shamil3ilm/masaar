<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Jobs;

use App\Domains\Compliance\Zatca\Client\ZatcaClient;
use App\Domains\Compliance\Zatca\Services\ClearanceStateManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to re-check clearance status for pending submissions.
 *
 * Handles ZATCA "partial success" scenarios where:
 * - HTTP 200 was received but clearance isn't confirmed
 * - Status was "PENDING" or unclear
 * - Need to poll ZATCA for final state
 */
class CheckClearanceStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    public function __construct(
        private readonly string $submissionId,
        private readonly string $invoiceUuid,
    ) {
    }

    public function handle(
        ZatcaClient $zatcaClient,
        ClearanceStateManager $stateManager
    ): void {
        Log::info('Checking clearance status', [
            'submission_id' => $this->submissionId,
            'invoice_uuid' => $this->invoiceUuid,
        ]);

        try {
            // Query ZATCA for current status
            $response = $zatcaClient->getInvoiceStatus($this->invoiceUuid);

            // Parse the response
            $parsedState = $stateManager->parseResponse($response);

            // Update state if we got a terminal state
            if ($parsedState['is_terminal']) {
                $stateManager->updateState(
                    $this->submissionId,
                    $parsedState['state'],
                    $response
                );

                Log::info('Clearance state resolved', [
                    'submission_id' => $this->submissionId,
                    'state' => $parsedState['state'],
                ]);

                return;
            }

            // Record the check attempt and schedule next if needed
            $result = $stateManager->recordCheckAttempt($this->submissionId, false);

            if ($result['scheduled']) {
                // Schedule next check
                self::dispatch($this->submissionId, $this->invoiceUuid)
                    ->delay(now()->addSeconds($result['delay_seconds']));

                Log::debug('Scheduled next clearance check', [
                    'submission_id' => $this->submissionId,
                    'next_check_at' => $result['next_check_at'],
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Clearance status check failed', [
                'submission_id' => $this->submissionId,
                'error' => $e->getMessage(),
            ]);

            // Re-throw to let the queue handle retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Clearance status check job failed permanently', [
            'submission_id' => $this->submissionId,
            'invoice_uuid' => $this->invoiceUuid,
            'error' => $exception->getMessage(),
        ]);
    }
}
