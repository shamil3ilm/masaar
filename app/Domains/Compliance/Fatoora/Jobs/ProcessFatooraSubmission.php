<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Jobs;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Events\InvoiceCleared;
use App\Domains\Compliance\Fatoora\Events\InvoiceFailed;
use App\Domains\Compliance\Fatoora\Events\InvoiceRejected;
use App\Domains\Compliance\Fatoora\Events\InvoiceReported;
use App\Domains\Compliance\Fatoora\Events\InvoiceSubmitted;
use App\Domains\Compliance\Fatoora\Events\InvoiceWarning;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\SubmissionIdempotency;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
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
class ProcessFatooraSubmission implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries;

    /**
     * Maximum processing time in seconds.
     */
    public int $timeout;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly InvoiceSubmission $submission
    ) {
        $this->tries = (int) config('fatoora.queue.tries', 3);
        $this->timeout = (int) config('fatoora.queue.timeout', 120);
        $this->onQueue('zatca-submissions');
    }

    /**
     * Get unique job ID.
     */
    public function uniqueId(): string
    {
        return 'zatca-submission-'.$this->submission->id;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('fatoora.queue.backoff', [10, 60, 300]);
    }

    /**
     * Execute the job.
     */
    public function handle(
        FatooraClient $zatcaClient,
        DocumentBuilder $complianceService
    ): void {
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

            // Load invoice and organization
            $invoice = $submission->invoice;
            $organization = $submission->org;

            // Generate compliance data (XML, hash, etc.)
            $complianceData = $complianceService->generateComplianceData(
                invoice: $invoice,
                organization: $organization,
                previousInvoiceHash: $invoice->previous_invoice_hash,
                privateKey: $organization->zatca_private_key,
                certificate: $organization->zatca_certificate,
            );

            $invoiceXml = $complianceData['xml'];
            $invoiceHash = $complianceData['hash'];
            $invoiceUuid = $invoice->id;

            // Submit to ZATCA
            $this->transitionState($submission, 'submitted', 'queue_job');

            // Fire submitted event for real-time tracking
            event(new InvoiceSubmitted($submission->fresh()));

            $result = $submission->isClearance()
                ? $zatcaClient->clearInvoice($invoiceXml, $invoiceHash, $invoiceUuid)
                : $zatcaClient->reportInvoice($invoiceXml, $invoiceHash, $invoiceUuid);

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
    private function handleZatcaResponse(InvoiceSubmission $submission, FatooraResponse $response): void
    {
        $success = $response->success;
        $hasWarnings = $response->hasWarnings();

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
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
            'zatca_warnings' => $response->warningMessages ?: null,
            'zatca_errors' => $response->errorMessages ?: null,
            'completed_at' => now(),
        ]);

        // Update idempotency
        $this->updateIdempotency($submission, $success, $response);

        // Log transition
        $this->logStateTransition($submission, 'submitted', $newState, 'zatca', [
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
        ]);

        // Fire appropriate event for real-time notifications
        $this->fireStateEvent($submission->fresh(), $newState, [
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
        ]);
    }

    /**
     * Handle submission error.
     */
    private function handleError(InvoiceSubmission $submission, Throwable $e): void
    {
        $errorCode = $e instanceof FatooraException
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
            'last_error' => $e->getMessage(),
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

        // Fire failed event for real-time notifications
        event(new InvoiceFailed($submission->fresh(), [
            'error_code' => $errorCode->value,
            'error_message' => $e->getMessage(),
            'attempt' => $this->attempts(),
            'will_retry' => $isRetryable && $this->attempts() < $this->tries,
        ]));
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
            'context' => ! empty($context) ? json_encode($context) : null,
            'actor_type' => 'system',
            'actor_id' => null,
            'ip_address' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Fire the appropriate event based on state.
     */
    private function fireStateEvent(InvoiceSubmission $submission, string $state, array $context = []): void
    {
        $event = match ($state) {
            'cleared' => new InvoiceCleared($submission, $context),
            'reported' => new InvoiceReported($submission, $context),
            'rejected' => new InvoiceRejected($submission, $context),
            'warning' => new InvoiceWarning($submission, $context),
            'failed' => new InvoiceFailed($submission, $context),
            default => null,
        };

        if ($event !== null) {
            event($event);
        }
    }

    /**
     * Update idempotency record with response.
     */
    private function updateIdempotency(InvoiceSubmission $submission, bool $success, FatooraResponse $response): void
    {
        SubmissionIdempotency::where('id', $submission->idempotency_id)->update([
            'status' => $success ? 'completed' : 'failed',
            'http_status_code' => $success ? 200 : 422,
            'response_body' => $response->rawResponse,
            'clearance_status' => $response->clearanceStatus,
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
            'last_error' => 'Max retries exceeded: '.$exception->getMessage(),
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

        // Fire permanent failure event
        event(new InvoiceFailed($submission->fresh(), [
            'reason' => 'max_retries_exceeded',
            'error_message' => $exception->getMessage(),
            'permanent' => true,
        ]));
    }
}
