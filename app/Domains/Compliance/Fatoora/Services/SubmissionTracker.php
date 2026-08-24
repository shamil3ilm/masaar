<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Events\BaseInvoiceEvent;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\SubmissionIdempotency;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Services\UsageMeteringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends an invoice to ZATCA once, and remembers what happened.
 *
 * Idempotency keys make a retry safe, the state machine records where a
 * submission got to, and a repeated request replays the first answer rather
 * than sending a second document. What must be true before any of that is
 * SubmissionGuard's decision.
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
        private readonly ClearanceState $clearanceState,
        private readonly SubmissionGuard $guard,
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

            return $this->processSynchronously($submission, $invoice);
        } catch (\Throwable $e) {
            return $this->handleSubmissionError($submission, $e);
        }
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
     */
    private function createSubmission(Invoice $invoice, string $idempotencyKey, bool $async): InvoiceSubmission
    {
        return DB::transaction(function () use ($invoice, $idempotencyKey, $async) {
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

            // Log state transition
            $this->logStateTransition($submission, null, $submission->state, 'api_call');

            return $submission;
        });
    }

    /**
     * Process submission synchronously.
     */
    private function processSynchronously(InvoiceSubmission $submission, Invoice $invoice): array
    {
        $this->transitionState($submission, 'submitted', 'api_call');

        try {
            // Extract required data from invoice
            $invoiceXml = $invoice->signed_xml;
            $invoiceHash = $invoice->hash;
            $invoiceUuid = $invoice->id;

            // Determine submission type and call ZATCA API
            $response = $invoice->isB2B()
                ? $this->zatcaClient->clearInvoice($invoiceXml, $invoiceHash, $invoiceUuid)
                : $this->zatcaClient->reportInvoice($invoiceXml, $invoiceHash, $invoiceUuid);

            return $this->handleZatcaResponse($submission, $response);
        } catch (\Exception $e) {
            throw $e;
        }
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
            // The route this points at, verbatim: routes/api/tenant.php:54
            // declares /compliance/sa/status/{submissionId}. This read
            // /compliance/zatca/submissions/{id}/status — the wrong prefix and
            // the wrong shape — so the URL handed to a caller polling for an
            // outcome had never resolved.
            'check_status_url' => "/api/compliance/sa/status/{$submission->id}",
        ];
    }

    /**
     * Handle ZATCA API response.
     */
    private function handleZatcaResponse(InvoiceSubmission $submission, FatooraResponse $response): array
    {
        $success = $response->success;
        $hasWarnings = $response->hasWarnings();

        // A 200 from ZATCA does not mean the invoice is cleared. For a B2B
        // document, "REPORTED" means received and not yet cleared, and only
        // "CLEARED" is terminal — so the state comes from what ZATCA actually
        // returned, never from the fact that the call succeeded.
        $clearance = $this->clearanceState->parseResponse([
            'clearanceStatus' => $response->clearanceStatus,
            'reportingStatus' => $response->reportingStatus,
            'validationResults' => $response->validationResults,
        ], isSimplified: $submission->submission_type !== 'clearance');

        $newState = match (true) {
            ! $success => 'rejected',
            $hasWarnings => 'warning',
            default => ClearanceState::submissionState($clearance['state']),
        };

        app(UsageMeteringService::class)->recordSubmissionOutcome(
            (string) $submission->org_id,
            $newState,
            (float) ($submission->invoice?->total ?? 0)
        );

        // Extract warnings and errors from validation results
        $warnings = $response->validationResults['warnings'] ?? $response->warningMessages;
        $errors = $response->validationResults['errors'] ?? $response->errorMessages;

        // Update submission
        $submission->update([
            'state' => $newState,
            'previous_state' => $submission->state,
            'state_changed_at' => now(),
            'zatca_uuid' => $response->validationResults['invoiceUuid'] ?? null,
            'invoice_hash' => $response->validationResults['invoiceHash'] ?? null,
            'clearance_status' => $response->clearanceStatus,
            'clearance_state' => $clearance['state'],
            // Only a terminal clearance counts as confirmed; a document still
            // awaiting ZATCA's decision has no confirmation time.
            'cleared_at' => $clearance['is_terminal'] ? now() : null,
            'reporting_status' => $response->reportingStatus,
            'zatca_warnings' => ! empty($warnings) ? $warnings : null,
            'zatca_errors' => ! empty($errors) ? $errors : null,
            'completed_at' => $clearance['is_terminal'] ? now() : null,
        ]);

        // Convert response to array for idempotency storage
        $responseArray = [
            'clearanceStatus' => $response->clearanceStatus,
            'reportingStatus' => $response->reportingStatus,
            'validationStatus' => $response->validationStatus,
            'validationResults' => $response->validationResults,
            'warningMessages' => $response->warningMessages,
            'errorMessages' => $response->errorMessages,
        ];

        // Update idempotency record
        $this->updateIdempotency($submission, $success, $responseArray);

        // Log state transition
        $this->logStateTransition($submission, 'submitted', $newState, 'zatca');

        // Announce the outcome, as the queued path does. This fired nowhere in
        // the synchronous path, so the same result produced a webhook when
        // queued and none when processed inline — what an integrator received
        // depended on which path the platform happened to take.
        BaseInvoiceEvent::raise($submission->fresh(), $newState, [
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
        ]);

        return [
            'success' => $success,
            'state' => $newState,
            'submission_id' => $submission->id,
            'zatca_uuid' => $submission->zatca_uuid,
            'clearance_status' => $submission->clearance_status,
            'reporting_status' => $submission->reporting_status,
            'warnings' => $submission->zatca_warnings,
            'errors' => $submission->zatca_errors,
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
        $this->logStateTransition($submission, $submission->previous_state, 'failed', 'error', [
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
     */
    public function retry(InvoiceSubmission $submission): array
    {
        if (! in_array($submission->state, ['failed', 'rejected'])) {
            throw new FatooraException(
                'Only failed or rejected submissions can be retried',
                ErrorCode::VAL_INVALID_FORMAT
            );
        }

        $errorCode = ErrorCode::tryFrom($submission->last_error_code);
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

        $invoice = $submission->invoice;

        // Reset state
        $this->transitionState($submission, 'pending_submission', 'retry');

        return $this->processSynchronously($submission, $invoice);
    }

    /**
     * Cancel a pending submission.
     */
    public function cancel(InvoiceSubmission $submission, string $reason): bool
    {
        if (! in_array($submission->state, ['draft', 'queued'])) {
            return false;
        }

        $this->transitionState($submission, 'cancelled', 'manual', ['reason' => $reason]);

        // Expire idempotency
        SubmissionIdempotency::where('id', $submission->idempotency_id)
            ->update(['status' => 'expired', 'expires_at' => now()]);

        return true;
    }

    /**
     * Transition submission state.
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
            'actor_type' => auth()->check() ? 'user' : 'system',
            'actor_id' => auth()->id(),
            'ip_address' => request()->ip(),
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
            'clearance_status' => $response['clearanceStatus'] ?? null,
            'completed_at' => now(),
        ]);
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
