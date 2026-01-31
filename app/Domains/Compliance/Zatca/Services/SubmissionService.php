<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Client\ZatcaClient;
use App\Domains\Compliance\Zatca\Enums\ErrorCode;
use App\Domains\Compliance\Zatca\Exceptions\ZatcaException;
use App\Domains\Compliance\Zatca\Jobs\ProcessZatcaSubmission;
use App\Domains\Compliance\Zatca\Models\InvoiceSubmission;
use App\Domains\Compliance\Zatca\Models\SubmissionIdempotency;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Submission Service with Idempotency and State Machine.
 *
 * Handles ZATCA invoice submissions with:
 * - Idempotency key support for retry safety
 * - State machine for submission lifecycle
 * - Response replay for duplicate requests
 * - Extreme scenario handling
 */
class SubmissionService
{
    /**
     * Get idempotency window in hours from config.
     */
    private function getIdempotencyWindowHours(): int
    {
        return (int) config('zatca.idempotency.window_hours', 24);
    }

    /**
     * Get maximum concurrent submissions per organization from config.
     */
    private function getMaxConcurrentSubmissions(): int
    {
        return (int) config('zatca.rate_limits.max_concurrent', 10);
    }

    public function __construct(
        private readonly ZatcaClient $zatcaClient,
        private readonly CertificateService $certificateService,
        private readonly XadesSigner $signer,
        private readonly ?TimestampValidator $timestampValidator = null,
    ) {}

    /**
     * Submit invoice to ZATCA with idempotency support.
     *
     * @param Invoice $invoice Invoice to submit
     * @param string|null $idempotencyKey Optional idempotency key
     * @param bool $async Whether to process asynchronously
     * @return array Submission result
     * @throws ZatcaException
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
        $this->performPreSubmissionChecks($invoice);

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

        if (!$idempotency) {
            return null;
        }

        // Check if request parameters match
        $requestHash = $this->computeRequestHash($invoice);
        if ($idempotency->request_hash !== $requestHash) {
            throw new ZatcaException(
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
     * Perform pre-submission checks for extreme scenarios.
     */
    private function performPreSubmissionChecks(Invoice $invoice): void
    {
        $organization = $invoice->organization;

        // 1. Check organization status
        if ($organization->is_suspended ?? false) {
            throw new ZatcaException(
                'Organization is suspended',
                ErrorCode::AUTH_ORGANIZATION_SUSPENDED
            );
        }

        // 2. Check certificate validity
        $this->checkCertificateHealth($organization);

        // 3. Check rate limits
        $this->checkRateLimits($organization);

        // 4. Check concurrent submissions
        $this->checkConcurrentSubmissions($organization);

        // 5. Check for duplicate submission
        $this->checkDuplicateSubmission($invoice);

        // 6. Verify invoice is in submittable state
        $this->verifyInvoiceState($invoice);

        // 7. Validate timestamp (±30 seconds drift enforcement)
        $this->validateInvoiceTimestamp($invoice);
    }

    /**
     * Validate invoice timestamp against system time and ERP time.
     *
     * Enforces ±30 second drift tolerance as per compliance policy.
     *
     * @see docs/COMPLIANCE-POLICIES.md Section 7: Timestamp Authority
     */
    private function validateInvoiceTimestamp(Invoice $invoice): void
    {
        if (!$this->timestampValidator) {
            return; // Skip if validator not injected
        }

        $invoiceTimestamp = $invoice->issue_date instanceof \DateTimeInterface
            ? $invoice->issue_date
            : new \DateTimeImmutable($invoice->issue_date);

        // Get ERP timestamp if available (from original import)
        $erpTimestamp = null;
        if (isset($invoice->erp_timestamp)) {
            try {
                $erpTimestamp = new \DateTimeImmutable($invoice->erp_timestamp);
            } catch (\Exception $e) {
                // Ignore invalid ERP timestamp
            }
        }

        $validation = $this->timestampValidator->validateTimestamps(
            $invoiceTimestamp,
            $erpTimestamp,
            null, // TSA timestamp added during signing
            null  // ZATCA received timestamp not yet known
        );

        // Log warnings but don't block
        if (!empty($validation['warnings'])) {
            Log::warning('Invoice timestamp validation warnings', [
                'invoice_id' => $invoice->id,
                'warnings' => $validation['warnings'],
                'drift_seconds' => $validation['drift_seconds'],
            ]);
        }

        // Block on errors (exceeds ±30 second tolerance)
        if (!$validation['valid']) {
            Log::error('Invoice timestamp validation failed', [
                'invoice_id' => $invoice->id,
                'errors' => $validation['errors'],
                'drift_seconds' => $validation['drift_seconds'],
            ]);

            throw new ZatcaException(
                'Invoice timestamp validation failed: ' . implode('; ', $validation['errors']),
                ErrorCode::VALIDATION_FAILED,
                [
                    'drift_seconds' => $validation['drift_seconds'],
                    'max_allowed' => TimestampValidator::MAX_DRIFT_SECONDS,
                ]
            );
        }
    }

    /**
     * Check certificate health with warnings for expiring certificates.
     */
    private function checkCertificateHealth($organization): void
    {
        $certificate = $organization->zatca_certificate ?? null;

        if (!$certificate) {
            throw new ZatcaException(
                'ZATCA certificate not found',
                ErrorCode::CERT_NOT_FOUND
            );
        }

        // Check validity
        if (!$this->certificateService->isValid($certificate)) {
            throw new ZatcaException(
                'ZATCA certificate is expired or invalid',
                ErrorCode::CERT_EXPIRED
            );
        }

        // Check expiry warning (30 days)
        $expiryDate = $this->certificateService->getExpiryDate($certificate);
        if ($expiryDate && $expiryDate->diffInDays(now()) <= 30) {
            Log::warning('ZATCA certificate expiring soon', [
                'organization_id' => $organization->id,
                'expires_at' => $expiryDate->toIso8601String(),
                'days_remaining' => $expiryDate->diffInDays(now()),
            ]);
        }

        // Check revocation (non-blocking if check fails)
        try {
            $revocationStatus = $this->certificateService->checkRevocationStatus($certificate);
            if ($revocationStatus['revoked']) {
                throw new ZatcaException(
                    'ZATCA certificate has been revoked',
                    ErrorCode::CERT_REVOKED
                );
            }
        } catch (ZatcaException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Certificate revocation check failed', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check rate limits.
     */
    private function checkRateLimits($organization): void
    {
        // Check per-minute rate limit
        $recentSubmissions = InvoiceSubmission::where('organization_id', $organization->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($recentSubmissions >= 60) {
            throw new ZatcaException(
                'Rate limit exceeded (60/minute)',
                ErrorCode::RATE_LIMIT_EXCEEDED
            );
        }

        // Check daily limit
        $dailySubmissions = InvoiceSubmission::where('organization_id', $organization->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($dailySubmissions >= 10000) {
            throw new ZatcaException(
                'Daily submission limit exceeded',
                ErrorCode::RATE_DAILY_LIMIT
            );
        }
    }

    /**
     * Check concurrent submission limits.
     */
    private function checkConcurrentSubmissions($organization): void
    {
        $inProgress = InvoiceSubmission::where('organization_id', $organization->id)
            ->whereIn('state', ['queued', 'pending_submission', 'submitted'])
            ->count();

        if ($inProgress >= $this->getMaxConcurrentSubmissions()) {
            throw new ZatcaException(
                'Maximum concurrent submissions reached',
                ErrorCode::RATE_CONCURRENT_LIMIT
            );
        }
    }

    /**
     * Check for duplicate submission.
     */
    private function checkDuplicateSubmission(Invoice $invoice): void
    {
        $existingSubmission = InvoiceSubmission::where('invoice_id', $invoice->id)
            ->whereIn('state', ['cleared', 'reported'])
            ->first();

        if ($existingSubmission) {
            $errorCode = $existingSubmission->state === 'cleared'
                ? ErrorCode::ZATCA_INVOICE_ALREADY_CLEARED
                : ErrorCode::ZATCA_INVOICE_ALREADY_REPORTED;

            throw new ZatcaException($errorCode->getMessage(), $errorCode);
        }
    }

    /**
     * Verify invoice is in a submittable state.
     */
    private function verifyInvoiceState(Invoice $invoice): void
    {
        // Check invoice has required data
        if (!$invoice->signed_xml) {
            throw new ZatcaException(
                'Invoice must be signed before submission',
                ErrorCode::VAL_MISSING_REQUIRED_FIELD
            );
        }

        if (!$invoice->hash) {
            throw new ZatcaException(
                'Invoice hash is missing',
                ErrorCode::ZATCA_INVALID_HASH
            );
        }

        if (!$invoice->qr_code) {
            throw new ZatcaException(
                'QR code is missing',
                ErrorCode::ZATCA_INVALID_QR_CODE
            );
        }
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
                'organization_id' => $invoice->organization_id,
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
                'organization_id' => $invoice->organization_id,
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
            // Determine submission type
            $result = $invoice->isB2B()
                ? $this->zatcaClient->clearInvoice($invoice)
                : $this->zatcaClient->reportInvoice($invoice);

            return $this->handleZatcaResponse($submission, $result);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Queue submission for async processing.
     */
    private function queueSubmission(InvoiceSubmission $submission): array
    {
        $job = new ProcessZatcaSubmission($submission);

        $submission->update([
            'queued_at' => now(),
            'queue_job_id' => $job->uniqueId(),
        ]);

        // Dispatch the job
        ProcessZatcaSubmission::dispatch($submission);

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
            'check_status_url' => "/api/compliance/zatca/submissions/{$submission->id}/status",
        ];
    }

    /**
     * Handle ZATCA API response.
     */
    private function handleZatcaResponse(InvoiceSubmission $submission, array $response): array
    {
        $success = $response['clearanceStatus'] === 'CLEARED'
            || $response['reportingStatus'] === 'REPORTED';

        $hasWarnings = !empty($response['validationResults']['warnings'] ?? []);

        // Determine final state
        $newState = match (true) {
            $success && $hasWarnings => 'warning',
            $success && $submission->submission_type === 'clearance' => 'cleared',
            $success => 'reported',
            default => 'rejected',
        };

        // Update submission
        $submission->update([
            'state' => $newState,
            'previous_state' => $submission->state,
            'state_changed_at' => now(),
            'zatca_uuid' => $response['invoiceUuid'] ?? null,
            'zatca_invoice_hash' => $response['invoiceHash'] ?? null,
            'clearance_status' => $response['clearanceStatus'] ?? null,
            'reporting_status' => $response['reportingStatus'] ?? null,
            'zatca_warnings' => $response['validationResults']['warnings'] ?? null,
            'zatca_errors' => $response['validationResults']['errors'] ?? null,
            'completed_at' => now(),
        ]);

        // Update idempotency record
        $this->updateIdempotency($submission, $success, $response);

        // Log state transition
        $this->logStateTransition($submission, 'submitted', $newState, 'zatca');

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
        $errorCode = $e instanceof ZatcaException
            ? $e->getErrorCode()
            : ErrorCode::SYS_INTERNAL_ERROR;

        $isRetryable = $errorCode->isRetryable();

        // Update submission
        $submission->update([
            'state' => 'failed',
            'previous_state' => $submission->state,
            'state_changed_at' => now(),
            'last_error_code' => $errorCode->value,
            'last_error_message' => $e->getMessage(),
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
        if (!in_array($submission->state, ['failed', 'rejected'])) {
            throw new ZatcaException(
                'Only failed or rejected submissions can be retried',
                ErrorCode::VAL_INVALID_FORMAT
            );
        }

        $errorCode = ErrorCode::tryFrom($submission->last_error_code);
        if ($errorCode && !$errorCode->isRetryable()) {
            throw new ZatcaException(
                'This error is not retryable',
                ErrorCode::VAL_INVALID_FORMAT
            );
        }

        if ($submission->retry_count >= ($errorCode?->getMaxRetries() ?? 3)) {
            throw new ZatcaException(
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
        if (!in_array($submission->state, ['draft', 'queued'])) {
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
            'context' => !empty($context) ? json_encode($context) : null,
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
            'zatca_clearance_status' => $response['clearanceStatus'] ?? null,
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
            $invoice->organization_id,
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

        if (!$submission) {
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
            'state_history' => $submission->stateLogs->map(fn($log) => [
                'from' => $log->from_state,
                'to' => $log->to_state,
                'trigger' => $log->trigger,
                'at' => $log->created_at->toIso8601String(),
            ])->toArray(),
        ];
    }
}
