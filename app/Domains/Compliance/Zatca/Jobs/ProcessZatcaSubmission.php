<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Jobs;

use App\Domains\Compliance\Zatca\Client\ZatcaClient;
use App\Domains\Compliance\Zatca\Enums\ErrorCode;
use App\Domains\Compliance\Zatca\Exceptions\ZatcaException;
use App\Domains\Compliance\Zatca\Models\InvoiceSubmission;
use App\Domains\Compliance\Zatca\Models\SubmissionIdempotency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Async ZATCA Submission Job.
 *
 * Processes invoice submissions asynchronously with:
 * - Automatic retries with exponential backoff
 * - State machine transitions
 * - Idempotency record updates
 * - Full audit logging
 */
class ProcessZatcaSubmission implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Backoff intervals in seconds.
     */
    public array $backoff = [10, 60, 300];

    /**
     * Maximum processing time in seconds.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly InvoiceSubmission $submission
    ) {
        $this->onQueue('zatca-submissions');
    }

    /**
     * Get unique job ID.
     */
    public function uniqueId(): string
    {
        return 'zatca-submission-' . $this->submission->id;
    }

    /**
     * Execute the job.
     */
    public function handle(ZatcaClient $zatcaClient): void
    {
        $submission = $this->submission->fresh();

        if ($submission->isTerminal()) {
            Log::info('Submission already in terminal state, skipping', [
                'submission_id' => $submission->id,
                'state' => $submission->state,
            ]);
            return;
        }

        Log::info('Processing ZATCA submission', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Transition to pending
            $this->transitionState($submission, 'pending_submission', 'queue_job');

            // Load invoice
            $invoice = $submission->invoice;

            // Submit to ZATCA
            $this->transitionState($submission, 'submitted', 'queue_job');

            $result = $submission->isClearance()
                ? $zatcaClient->clearInvoice($invoice)
                : $zatcaClient->reportInvoice($invoice);

            // Handle response
            $this->handleZatcaResponse($submission, $result);

            Log::info('ZATCA submission processed successfully', [
                'submission_id' => $submission->id,
                'state' => $submission->fresh()->state,
            ]);
        } catch (Throwable $e) {
            $this->handleError($submission, $e);
            throw $e; // Re-throw for queue retry mechanism
        }
    }

    /**
     * Handle ZATCA API response.
     */
    private function handleZatcaResponse(InvoiceSubmission $submission, array $response): void
    {
        $success = ($response['clearanceStatus'] ?? null) === 'CLEARED'
            || ($response['reportingStatus'] ?? null) === 'REPORTED';

        $hasWarnings = !empty($response['validationResults']['warnings'] ?? []);

        // Determine final state
        $newState = match (true) {
            $success && $hasWarnings => 'warning',
            $success && $submission->isClearance() => 'cleared',
            $success => 'reported',
            default => 'rejected',
        };

        // Update submission
        $submission->update([
            'state' => $newState,
            'previous_state' => 'submitted',
            'state_changed_at' => now(),
            'zatca_uuid' => $response['invoiceUuid'] ?? null,
            'zatca_invoice_hash' => $response['invoiceHash'] ?? null,
            'clearance_status' => $response['clearanceStatus'] ?? null,
            'reporting_status' => $response['reportingStatus'] ?? null,
            'zatca_warnings' => $response['validationResults']['warnings'] ?? null,
            'zatca_errors' => $response['validationResults']['errors'] ?? null,
            'completed_at' => now(),
        ]);

        // Update idempotency
        $this->updateIdempotency($submission, $success, $response);

        // Log transition
        $this->logStateTransition($submission, 'submitted', $newState, 'zatca', [
            'clearance_status' => $response['clearanceStatus'] ?? null,
            'reporting_status' => $response['reportingStatus'] ?? null,
        ]);
    }

    /**
     * Handle submission error.
     */
    private function handleError(InvoiceSubmission $submission, Throwable $e): void
    {
        $errorCode = $e instanceof ZatcaException
            ? $e->getErrorCode()
            : ErrorCode::SYS_INTERNAL_ERROR;

        $isRetryable = $errorCode->isRetryable();
        $maxRetries = $errorCode->getMaxRetries();

        // Update submission
        $submission->update([
            'state' => 'failed',
            'previous_state' => $submission->state,
            'state_changed_at' => now(),
            'last_error_code' => $errorCode->value,
            'last_error_message' => $e->getMessage(),
            'retry_count' => $submission->retry_count + 1,
            'next_retry_at' => $isRetryable && $this->attempts() < $this->tries
                ? now()->addSeconds($this->backoff[$this->attempts() - 1] ?? 300)
                : null,
        ]);

        // Update idempotency
        $idempotency = SubmissionIdempotency::find($submission->idempotency_id);
        if ($idempotency) {
            $idempotency->update([
                'status' => $isRetryable && $this->attempts() < $this->tries
                    ? 'processing'
                    : 'failed',
                'attempt_count' => $idempotency->attempt_count + 1,
                'last_attempt_at' => now(),
            ]);
        }

        // Log
        $this->logStateTransition($submission, $submission->previous_state, 'failed', 'error', [
            'error_code' => $errorCode->value,
            'error_message' => $e->getMessage(),
            'attempt' => $this->attempts(),
            'will_retry' => $isRetryable && $this->attempts() < $this->tries,
        ]);

        Log::error('ZATCA submission failed', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'error_code' => $errorCode->value,
            'error' => $e->getMessage(),
            'attempt' => $this->attempts(),
            'will_retry' => $isRetryable && $this->attempts() < $this->tries,
        ]);
    }

    /**
     * Transition state with logging.
     */
    private function transitionState(
        InvoiceSubmission $submission,
        string $newState,
        string $trigger,
        array $context = []
    ): void {
        $oldState = $submission->state;

        $submission->update([
            'state' => $newState,
            'previous_state' => $oldState,
            'state_changed_at' => now(),
            'submitted_at' => $newState === 'submitted' ? now() : $submission->submitted_at,
        ]);

        $this->logStateTransition($submission, $oldState, $newState, $trigger, $context);
    }

    /**
     * Log state transition for audit.
     */
    private function logStateTransition(
        InvoiceSubmission $submission,
        ?string $fromState,
        string $toState,
        string $trigger,
        array $context = []
    ): void {
        DB::table('submission_state_logs')->insert([
            'id' => Str::uuid()->toString(),
            'submission_id' => $submission->id,
            'from_state' => $fromState,
            'to_state' => $toState,
            'trigger' => $trigger,
            'context' => !empty($context) ? json_encode($context) : null,
            'actor_type' => 'system',
            'actor_id' => null,
            'ip_address' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Update idempotency record with response.
     */
    private function updateIdempotency(InvoiceSubmission $submission, bool $success, array $response): void
    {
        SubmissionIdempotency::where('id', $submission->idempotency_id)->update([
            'status' => $success ? 'completed' : 'failed',
            'http_status_code' => $success ? 200 : 422,
            'response_body' => $response,
            'zatca_request_id' => $response['requestId'] ?? null,
            'zatca_clearance_status' => $response['clearanceStatus'] ?? null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        $submission = $this->submission->fresh();

        // Final failure - mark as permanently failed
        $submission->update([
            'state' => 'failed',
            'state_changed_at' => now(),
            'last_error_message' => 'Max retries exceeded: ' . $exception->getMessage(),
            'next_retry_at' => null,
        ]);

        // Update idempotency
        SubmissionIdempotency::where('id', $submission->idempotency_id)
            ->update(['status' => 'failed']);

        $this->logStateTransition($submission, $submission->state, 'failed', 'error', [
            'reason' => 'max_retries_exceeded',
            'final_error' => $exception->getMessage(),
        ]);

        Log::error('ZATCA submission permanently failed', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
