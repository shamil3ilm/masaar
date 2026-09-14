<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\SubmissionIdempotency;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends an invoice to ZATCA once, and remembers what happened.
 *
 * Idempotency keys make a retry safe, the state machine records where a
 * submission got to, and a repeated request replays the first answer rather
 * than sending a second document. What must be true before any of that is
 * SubmissionGuard's decision; how a submission moves between states and how
 * an answer is recorded is SubmissionLedger's.
 */
class SubmissionTracker
{
    /**
     * Get idempotency window in hours from config.
     */
    private function getIdempotencyWindowHours(): int
    {
        return (int) config('fatoora.idempotency.window_hours', 24);
    }

    /**
     * Every dependency is required, none optional.
     */
    public function __construct(
        private readonly FatooraClient $zatcaClient,
        private readonly SubmissionGuard $guard,
        private readonly SubmissionLedger $ledger,
    ) {}

    /**
     * Submit invoice to ZATCA with idempotency support.
     *
     * @param  Invoice  $invoice  Invoice to submit
     * @param  string|null  $idempotencyKey  Optional idempotency key
     * @param  bool  $async  Whether to process asynchronously
     * @return array Submission result
     *
     * @throws FatooraException
     */
    public function submit(Invoice $invoice, ?string $idempotencyKey = null, bool $async = false): array
    {
        // Generate idempotency key if not provided
        $idempotencyKey = $idempotencyKey ?? $this->generateIdempotencyKey($invoice);

        // Check for existing idempotent request
        $existing = $this->checkIdempotency($idempotencyKey, $invoice);
        if ($existing) {
            return $existing;
        }

        // Pre-submission checks
        $this->guard->check($invoice);

        // Create submission record
        $submission = $this->createSubmission($invoice, $idempotencyKey, $async);

        try {
            if ($async) {
                return $this->queueSubmission($submission);
            }

            $response = $this->send($submission, $invoice);
        } catch (\Throwable $e) {
            return $this->handleSubmissionError($submission, $e);
        }

        // Outside the error handling above: ZATCA has answered, so a local
        // failure must not mark the submission failed and invite a retry.
        return $this->recordResponse($submission, $response);
    }

    /**
     * Check idempotency and return cached response if available.
     */
    private function checkIdempotency(string $idempotencyKey, Invoice $invoice): ?array
    {
        $idempotency = SubmissionIdempotency::where('idempotency_key', $idempotencyKey)
            ->where('expires_at', '>', now())
            ->first();

        if (! $idempotency) {
            return null;
        }

        // Check if request parameters match
        $requestHash = $this->computeRequestHash($invoice);
        if ($idempotency->request_hash !== $requestHash) {
            throw new FatooraException(
                ErrorCode::IDEM_REQUEST_MISMATCH->getMessage(),
                ErrorCode::IDEM_REQUEST_MISMATCH
            );
        }

        // If still processing, return appropriate response
        if ($idempotency->status === 'processing') {
            return [
                'success' => false,
                'error' => ErrorCode::IDEM_PROCESSING_IN_PROGRESS->toArray(),
                'idempotency_key' => $idempotencyKey,
                'retry_after' => 5,
            ];
        }

        // Return cached response
        Log::info('Returning idempotent response', [
            'idempotency_key' => $idempotencyKey,
            'invoice_id' => $invoice->id,
        ]);

        return [
            'success' => $idempotency->status === 'completed',
            'data' => $idempotency->response_body,
            'idempotency_key' => $idempotencyKey,
            'cached' => true,
            'original_request_at' => $idempotency->first_attempt_at->toIso8601String(),
        ];
    }

    /**
     * Create submission and idempotency records.
     *
     * The invoice row is locked and the invoice checked again for a submission
     * in flight or accepted. The guard's look happens before this transaction,
     * so a second request — under another idempotency key, such as the default
     * key after midnight — could pass it too and send the invoice twice.
     */
    private function createSubmission(Invoice $invoice, string $idempotencyKey, bool $async): InvoiceSubmission
    {
        return DB::transaction(function () use ($invoice, $idempotencyKey, $async) {
            Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

            $this->guard->assertNotSubmitted($invoice);

            // Create idempotency record
            $idempotency = SubmissionIdempotency::create([
                'idempotency_key' => $idempotencyKey,
                'invoice_id' => $invoice->id,
                'org_id' => $invoice->org_id,
                'request_hash' => $this->computeRequestHash($invoice),
                'endpoint' => $invoice->isB2B() ? '/clearance' : '/reporting',
                'method' => 'POST',
                'status' => 'processing',
                'first_attempt_at' => now(),
                'last_attempt_at' => now(),
                'expires_at' => now()->addHours($this->getIdempotencyWindowHours()),
            ]);

            // Create submission record
            $submission = InvoiceSubmission::create([
                'invoice_id' => $invoice->id,
                'org_id' => $invoice->org_id,
                'idempotency_id' => $idempotency->id,
                'state' => $async ? 'queued' : 'pending_submission',
                'submission_type' => $invoice->isB2B() ? 'clearance' : 'reporting',
                'submission_mode' => $async ? 'async' : 'sync',
                'state_changed_at' => now(),
            ]);

            $this->ledger->log($submission, null, $submission->state, 'api_call');

            return $submission;
        });
    }

    /**
     * Put the issued document in front of ZATCA.
     */
    private function send(InvoiceSubmission $submission, Invoice $invoice): FatooraResponse
    {
        $this->ledger->transition($submission, 'submitted', 'api_call');

        return $invoice->isB2B()
            ? $this->zatcaClient->clearInvoice($invoice->signed_xml, $invoice->hash, $invoice->id)
            : $this->zatcaClient->reportInvoice($invoice->signed_xml, $invoice->hash, $invoice->id);
    }

    /**
     * Record ZATCA's answer and describe it to the caller.
     *
     * 'recorded' is false when the answer could not be written locally. The
     * submission then stays 'submitted' for reconciliation and is not retried.
     */
    private function recordResponse(InvoiceSubmission $submission, FatooraResponse $response): array
    {
        $state = $this->ledger->recordResponse($submission, $response);

        return [
            'success' => $response->success,
            'state' => $state ?? $submission->state,
            'recorded' => $state !== null,
            'submission_id' => $submission->id,
            'zatca_uuid' => $submission->zatca_uuid,
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
            'warnings' => $submission->zatca_warnings,
            'errors' => $submission->zatca_errors,
        ];
    }

    /**
     * Queue submission for async processing.
     */
    private function queueSubmission(InvoiceSubmission $submission): array
    {
        $job = new ProcessFatooraSubmission($submission);

        $submission->update([
            'queued_at' => now(),
            'queue_job_id' => $job->uniqueId(),
        ]);

        // Dispatch the job
        ProcessFatooraSubmission::dispatch($submission);

        Log::info('ZATCA submission queued', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'job_id' => $job->uniqueId(),
        ]);

        return [
            'success' => true,
            'status' => 'queued',
            'submission_id' => $submission->id,
            'job_id' => $job->uniqueId(),
            'message' => 'Submission queued for processing',
            // Must match routes/api/tenant.php, which declares
            // /compliance/sa/status/{submissionId}. A caller polls this URL for
            // the outcome, so a shape that does not route leaves them waiting
            // on a 404.
            'check_status_url' => "/api/compliance/sa/status/{$submission->id}",
        ];
    }

    /**
     * Handle submission error.
     */
    private function handleSubmissionError(InvoiceSubmission $submission, \Throwable $e): array
    {
        $errorCode = $e instanceof FatooraException
            ? $e->getErrorCode()
            : ErrorCode::SYS_INTERNAL_ERROR;

        $isRetryable = $errorCode->isRetryable();

        // Update submission
        $submission->update([
            'state' => 'failed',
            'previous_state' => $submission->state,
            'state_changed_at' => now(),
            'last_error_code' => $errorCode->value,
            'last_error' => $e->getMessage(),
            'retry_count' => $submission->retry_count + 1,
            'next_retry_at' => $isRetryable && $submission->retry_count < $errorCode->getMaxRetries()
                ? now()->addSeconds($errorCode->getRetryDelay())
                : null,
        ]);

        // Update idempotency
        $idempotency = SubmissionIdempotency::find($submission->idempotency_id);
        if ($idempotency) {
            $idempotency->update([
                'status' => $isRetryable ? 'processing' : 'failed',
                'attempt_count' => $idempotency->attempt_count + 1,
                'last_attempt_at' => now(),
            ]);
        }

        // Log
        $this->ledger->log($submission, $submission->previous_state, 'failed', 'error', [
            'error_code' => $errorCode->value,
            'error_message' => $e->getMessage(),
            'retryable' => $isRetryable,
        ]);

        Log::error('ZATCA submission failed', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'error_code' => $errorCode->value,
            'error' => $e->getMessage(),
            'retryable' => $isRetryable,
        ]);

        return [
            'success' => false,
            'error' => $errorCode->toArray(),
            'submission_id' => $submission->id,
            'can_retry' => $isRetryable && $submission->retry_count < $errorCode->getMaxRetries(),
            'retry_after' => $isRetryable ? $errorCode->getRetryDelay() : null,
        ];
    }

    /**
     * Retry a failed submission.
     *
     * The submission is claimed under a row lock before anything is sent, so
     * two retries of the same submission send it once: the second finds the
     * state the first moved it to and is refused.
     */
    public function retry(InvoiceSubmission $submission): array
    {
        $claimed = $this->ledger->claim(
            $submission,
            ['failed', 'rejected'],
            'retry',
            $this->assertRetryable(...)
        );

        if ($claimed === null) {
            throw new FatooraException(
                'Only failed or rejected submissions can be retried',
                ErrorCode::VAL_INVALID_FORMAT
            );
        }

        return $this->recordResponse($claimed, $this->send($claimed, $claimed->invoice));
    }

    /**
     * Refuse a retry the error or the attempt count does not allow.
     *
     * @throws FatooraException
     */
    private function assertRetryable(InvoiceSubmission $submission): void
    {
        $errorCode = ErrorCode::tryFrom((string) $submission->last_error_code);
        if ($errorCode && ! $errorCode->isRetryable()) {
            throw new FatooraException(
                'This error is not retryable',
                ErrorCode::VAL_INVALID_FORMAT
            );
        }

        if ($submission->retry_count >= ($errorCode?->getMaxRetries() ?? 3)) {
            throw new FatooraException(
                'Maximum retry attempts exceeded',
                ErrorCode::RATE_QUOTA_EXCEEDED
            );
        }
    }

    /**
     * Cancel a pending submission.
     */
    public function cancel(InvoiceSubmission $submission, string $reason): bool
    {
        if (! in_array($submission->state, ['draft', 'queued'])) {
            return false;
        }

        $this->ledger->transition($submission, 'cancelled', 'manual', ['reason' => $reason]);

        // Expire idempotency
        SubmissionIdempotency::where('id', $submission->idempotency_id)
            ->update(['status' => 'expired', 'expires_at' => now()]);

        return true;
    }

    /**
     * Generate idempotency key for invoice.
     */
    private function generateIdempotencyKey(Invoice $invoice): string
    {
        return hash('sha256', implode(':', [
            $invoice->id,
            $invoice->org_id,
            $invoice->hash,
            now()->format('Y-m-d'),
        ]));
    }

    /**
     * Compute request hash for idempotency comparison.
     * Uses SHA256 for cryptographic consistency with ZATCA requirements.
     */
    private function computeRequestHash(Invoice $invoice): string
    {
        return hash('sha256', implode(':', [
            $invoice->id,
            $invoice->hash,
            $invoice->signed_xml ? hash('sha256', $invoice->signed_xml) : '',
        ]));
    }

    /**
     * Get submission status with full details.
     */
    public function getStatus(string $submissionId): ?array
    {
        $submission = InvoiceSubmission::with(['invoice', 'stateLogs'])->find($submissionId);

        if (! $submission) {
            return null;
        }

        return [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'state' => $submission->state,
            'submission_type' => $submission->submission_type,
            'submission_mode' => $submission->submission_mode,
            'zatca_uuid' => $submission->zatca_uuid,
            'clearance_status' => $submission->clearance_status,
            'reporting_status' => $submission->reporting_status,
            'warnings' => $submission->zatca_warnings,
            'errors' => $submission->zatca_errors,
            'retry_count' => $submission->retry_count,
            'can_retry' => in_array($submission->state, ['failed']) &&
                $submission->retry_count < $submission->max_retries,
            'next_retry_at' => $submission->next_retry_at?->toIso8601String(),
            'queued_at' => $submission->queued_at?->toIso8601String(),
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'completed_at' => $submission->completed_at?->toIso8601String(),
            'state_history' => $submission->stateLogs->map(fn ($log) => [
                'from' => $log->from_state,
                'to' => $log->to_state,
                'trigger' => $log->trigger,
                'at' => $log->created_at->toIso8601String(),
            ])->toArray(),
        ];
    }
}
