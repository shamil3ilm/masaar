<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\SubmissionIdempotency;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        private readonly Filer $filer,
        private readonly SubmissionGuard $guard,
        private readonly SubmissionLedger $ledger,
        private readonly UsageMeteringService $usage,
        private readonly CredentialStore $credentials,
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
     * File an issued invoice now and give back the authority's own answer.
     *
     * For a caller that wants the answer rather than a summary of it
     * (Submitter::submit). It used to send the document itself and record
     * only the invoice's verdict, so a filing it made had no submission row:
     * no state, no history, and nothing for the reconciliation hold or the
     * duplicate check to see. Now it opens a row like every other path, and
     * the ledger records the answer - a clearance that may have reached
     * ZATCA is held as submitted.
     *
     * @param  (\Closure(FatooraResponse): void)|null  $alongside  recorded with the answer, in its transaction
     *
     * @throws \Throwable what sending threw, after the row records it failed
     */
    public function fileNow(Invoice $invoice, ?\Closure $alongside = null): FatooraResponse
    {
        $this->guard->check($invoice);

        // A key of its own: a direct filing replays nothing.
        $submission = $this->createSubmission($invoice, hash('sha256', $invoice->id.':'.Str::uuid()->toString()), async: false);

        try {
            $response = $this->send($submission, $invoice);
        } catch (\Throwable $e) {
            $this->handleSubmissionError($submission, $e);

            throw $e;
        }

        // Unrecorded, the submission stays submitted for reconciliation, and
        // this caller - waiting on the answer - is told, not handed it.
        if ($this->ledger->recordResponse($submission, $response, $alongside) === null) {
            throw new \RuntimeException('ZATCA answered, but the answer could not be recorded; the submission is held for reconciliation.');
        }

        return $response;
    }

    /**
     * Record an invoice going into the offline queue.
     *
     * createSubmission's own comment calls itself "the one place every
     * submission path passes", and it was not: OfflineFallback wrote the
     * signed document and the queue item and returned. So an invoice filed
     * during an outage reserved no allowance - and forcing offline mode is a
     * switch on the admin screen, which made the whole monthly quota optional
     * - and appeared on no submissions screen, because the portal and the
     * admin logs both read invoice_submissions.
     *
     * An existing row is reused rather than added to. The ordinary way into
     * the offline queue is an online attempt that failed on connectivity,
     * which has already created a row and already taken a slot; a second row
     * would charge the taxpayer twice for one invoice. assertNotSubmitted
     * still runs first, so an invoice the authority already holds is refused
     * rather than quietly queued again.
     */
    public function recordOffline(Invoice $invoice): InvoiceSubmission
    {
        return DB::transaction(function () use ($invoice): InvoiceSubmission {
            $existing = InvoiceSubmission::acrossTenants()->where('invoice_id', $invoice->id)->latest('created_at')->first();

            if ($existing !== null) {
                $this->guard->assertNotSubmitted($invoice);
                $this->ledger->transition($existing, 'queued', 'offline_fallback');

                return $existing;
            }

            return $this->createSubmission($invoice, $this->generateIdempotencyKey($invoice), async: true);
        });
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

            // The invoice allowance, here rather than in middleware.
            //
            // license.quota is on two partner routes, so the tenant API and
            // portal issuance both filed without ever checking it. This is the one place every submission path
            // passes - the offline queue reaches it through recordOffline -
            // and it is already a transaction, so the slot is taken under the
            // same commit as the row that uses it.
            $this->usage->reserveInvoiceQuotaFor((string) $invoice->org_id);

            $type = InvoiceSubmission::typeFor($invoice);

            // Create idempotency record
            $idempotency = SubmissionIdempotency::create([
                'idempotency_key' => $idempotencyKey,
                'invoice_id' => $invoice->id,
                'org_id' => $invoice->org_id,
                'request_hash' => $this->computeRequestHash($invoice),
                'endpoint' => '/'.$type,
                'method' => 'POST',
                'status' => 'processing',
                'first_attempt_at' => now(),
                'last_attempt_at' => now(),
                'expires_at' => now()->addHours($this->getIdempotencyWindowHours()),
            ]);

            return $this->ledger->open([
                'invoice_id' => $invoice->id,
                'org_id' => $invoice->org_id,
                'idempotency_id' => $idempotency->id,
                'state' => $async ? 'queued' : 'pending_submission',
                'submission_type' => $type,
                'submission_mode' => $async ? 'async' : 'sync',
            ], 'api_call');
        });
    }

    /**
     * Put the issued document in front of ZATCA.
     */
    private function send(InvoiceSubmission $submission, Invoice $invoice): FatooraResponse
    {
        // Before the transition: a device with no credentials has sent
        // nothing, and must not be left in 'submitted', which nothing resends.
        $device = $this->credentials->forInvoice((string) $invoice->org_id, $invoice->device_id, $invoice->branch_id, (string) $invoice->id, $invoice->signed_xml);

        $this->ledger->transition($submission, 'submitted', 'api_call');

        return $this->filer->send($submission, $invoice->signed_xml, $invoice->hash, $invoice->id, $device);
    }

    /**
     * Record ZATCA's answer and describe it to the caller.
     *
     * 'recorded' is false when the answer could not be written locally. The
     * submission then stays 'submitted' for reconciliation and is not retried.
     *
     * 'error' carries the code on a refusal, in the same shape
     * handleSubmissionError uses. Without it the two failure paths describe
     * themselves differently: a thrown error arrives with a code and an
     * answered-but-refused one arrives with nothing, so a caller deciding an
     * HTTP status from it has to treat a 503 from the authority as a
     * malformed invoice.
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
            // As text for whoever reads them out; the rules themselves under
            // rejections, as the submission keeps them.
            'warnings' => FatooraResponse::messages((array) ($submission->zatca_warnings ?? [])),
            'errors' => FatooraResponse::messages((array) ($submission->zatca_errors ?? [])),
            'rejections' => $response->success ? [] : FatooraResponse::details((array) ($submission->zatca_errors ?? [])),
            'error' => $response->success ? null : $response->errorCode?->toArray(),
            'retry_after' => $response->isRetryable() ? $response->errorCode?->getRetryDelay() : null,
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

        // The queue's backend unreachable - a missing driver, a server down -
        // is not the invoice's failure, and not "filed". The submission stays
        // queued, which is retried (fatoora:recover-submissions dispatches a
        // stale queued row again), with the reason on it for every screen.
        //
        // After commit: nothing here opens a transaction, so it is pushed at
        // once today. A caller that wrapped this in one would otherwise hand a
        // worker on another connection a row it cannot see yet.
        try {
            ProcessFatooraSubmission::dispatch($submission)->afterCommit();
        } catch (\Throwable $e) {
            Log::error('A ZATCA submission could not be handed to the queue; it stays queued and is sent again', [
                'submission_id' => $submission->id,
                'invoice_id' => $submission->invoice_id,
                'error' => $e->getMessage(),
            ]);

            $this->ledger->transition($submission, SubmissionState::Queued, 'dispatch_failed', ['error' => $e->getMessage()], [
                'last_error_code' => ErrorCode::SYS_INTERNAL_ERROR->value,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return [
                'success' => false,
                'state' => $submission->state,
                'submission_id' => $submission->id,
                'errors' => [__('Filing could not be started: :reason', ['reason' => $e->getMessage()])],
                'retryable' => true,
            ];
        }

        Log::info('ZATCA submission queued', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'job_id' => $job->uniqueId(),
        ]);

        // A queue that runs the job at once has an answer already: where the
        // submission is now, so a caller words its message by that.
        $now = $submission->fresh();

        return [
            'success' => true,
            'status' => 'queued',
            'state' => $now?->state,
            'last_error' => $now?->last_error,
            'submission_id' => $submission->id,
            'job_id' => $job->uniqueId(),
            'message' => 'Submission queued for processing',
            // No status URL. The one given here named the submission id to a
            // route that looks up an invoice id, so it answered 404, and on
            // the tenant surface whichever one the caller used. The outcome is
            // read from the invoice (/compliance/sa/status/{invoiceId}, or
            // GET /v1/invoices/{id}) and announced by webhook.
        ];
    }

    /**
     * Handle submission error.
     */
    private function handleSubmissionError(InvoiceSubmission $submission, \Throwable $e): array
    {
        $errorCode = $e instanceof ComplianceException
            ? $e->getErrorCode()
            : ErrorCode::SYS_INTERNAL_ERROR;

        $isRetryable = $errorCode->isRetryable();

        $this->ledger->transition($submission, SubmissionState::Failed, 'error', [
            'error_code' => $errorCode->value,
            'error_message' => $e->getMessage(),
            'retryable' => $isRetryable,
        ], [
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

        Log::error('ZATCA submission failed', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'error_code' => $errorCode->value,
            'error' => $e->getMessage(),
            'retryable' => $isRetryable,
        ]);

        return [
            'success' => false,
            'state' => $submission->state,
            'error' => $errorCode->toArray(),
            // So a caller reading 'errors' - the issuing step - does not
            // take a failure for a filing.
            'errors' => [$e->getMessage()],
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
     * Send a failed submission again, because a person asked to: an
     * organization's administrator in the portal, or a platform admin.
     *
     * Only from failed, or a held clearance (InvoiceSubmission::isResendable):
     * a rejection is the authority's verdict, which the same document earns
     * again. A held clearance is released to failed first and sent as it was
     * signed, so ZATCA's answer reconciles it. Unlike the automatic retry, a person may send one
     * whose error was not retryable - our own fault, a queue that was down -
     * once whatever stopped it is fixed. Every check runs again first.
     *
     * @throws FatooraException when it may not be sent now, saying why
     */
    public function resend(InvoiceSubmission $submission): array
    {
        if (! $submission->isResendable()) {
            throw new FatooraException(__('Only a submission that failed can be sent again.'), ErrorCode::VAL_INVALID_FORMAT);
        }

        // Released first: the guard counts a submitted row as in flight. A
        // refusal after this leaves it failed, which is sent again as well.
        if ($submission->isHeld()) {
            $this->ledger->releaseHeld($submission, 'reconcile');
        }

        $invoice = $submission->invoice;
        $this->guard->check($invoice);

        $claimed = $this->ledger->claim($submission, [SubmissionState::Failed->value], 'resend');

        if ($claimed === null) {
            throw new FatooraException(__('Only a submission that failed can be sent again.'), ErrorCode::VAL_INVALID_FORMAT);
        }

        // The failure gave its slot back; this attempt takes one again.
        $this->usage->reserveInvoiceQuotaFor((string) $claimed->org_id);

        try {
            $response = $this->send($claimed, $invoice);
        } catch (\Throwable $e) {
            return $this->handleSubmissionError($claimed, $e);
        }

        return $this->recordResponse($claimed, $response);
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
