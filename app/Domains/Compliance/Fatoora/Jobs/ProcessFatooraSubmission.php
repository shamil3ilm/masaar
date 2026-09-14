<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Jobs;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Events\InvoiceFailed;
use App\Domains\Compliance\Fatoora\Events\InvoiceSubmitted;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\SubmissionIdempotency;
use App\Domains\Compliance\Fatoora\Services\KillSwitch;
use App\Domains\Compliance\Fatoora\Services\SubmissionLedger;
use App\Domains\Compliance\Fatoora\Services\Submitter;
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
     * States the job sends from. A submission pending or submitted is being
     * sent by another worker, or was answered and awaits reconciliation.
     */
    private const SENDABLE_STATES = ['draft', 'queued', 'failed', 'rejected'];

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
        // From config rather than hardcoded. fatoora.queue.name and .connection
        // existed and were read nowhere, so renaming the queue or pointing it at
        // another connection moved nothing — and an operator running a worker on
        // the name they had configured would watch an empty queue.
        $this->onQueue((string) config('fatoora.queue.name', 'zatca-submissions'));

        // Unset — null or empty — leaves the application's own connection in
        // place. Empty matters because that is what an unset .env value reads
        // as, and it is how .env.example documents "use the default".
        if (($connection = (string) config('fatoora.queue.connection')) !== '') {
            $this->onConnection($connection);
        }
    }

    /**
     * Identifies this submission's job in invoice_submissions.queue_job_id.
     *
     * The job is not ShouldBeUnique. What stops a duplicate job from sending
     * is SubmissionLedger::claim(), which holds the submission row, so it
     * still holds when the queue's cache loses a uniqueness lock.
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
     * How long until the next attempt, for the column that reports it.
     *
     * The queue applies backoff() itself. next_retry_at exists so an operator
     * reading invoice_submissions sees the same answer, which means deriving
     * it from the same list rather than a second constant.
     *
     * Past the end of the list the queue repeats its final value, so this does
     * too.
     */
    private function retryDelay(): int
    {
        $backoff = $this->backoff();
        $index = max(0, $this->attempts() - 1);

        return (int) ($backoff[$index] ?? end($backoff) ?: 300);
    }

    /**
     * Execute the job.
     */
    public function handle(
        FatooraClient $zatcaClient,
        KillSwitch $killSwitch,
        Submitter $submitter,
        SubmissionLedger $ledger
    ): void {
        // Checked here as well as before queueing, because the gap between the
        // two is exactly when an operator throws the switch. A job queued
        // before an incident would otherwise submit during it.
        $killSwitch->assertNotEnabled(KillSwitch::SWITCH_SUBMISSION, (string) $this->submission->org_id);

        $submission = $ledger->claim($this->submission, self::SENDABLE_STATES, 'queue_job');

        if ($submission === null) {
            Log::info('Submission is not waiting to be sent, skipping', [
                'submission_id' => $this->submission->id,
                'state' => $this->submission->fresh()?->state,
            ]);

            return;
        }

        Log::info('Processing ZATCA submission', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'attempt' => $this->attempts(),
        ]);

        try {
            $response = $this->send($submission, $zatcaClient, $submitter, $ledger);
        } catch (Throwable $e) {
            $this->handleError($submission, $e);
            throw $e; // Re-throw for queue retry mechanism
        }

        // Outside the error handling above: ZATCA has answered, so nothing from
        // here may mark the submission failed and have the queue send it again.
        // recordResponse() logs its own failures and leaves it 'submitted'.
        $state = $ledger->recordResponse($submission, $response);

        Log::info('ZATCA submission processed', [
            'submission_id' => $submission->id,
            'state' => $state,
            'recorded' => $state !== null,
        ]);
    }

    /**
     * Issue the document if needed and put it in front of ZATCA.
     */
    private function send(
        InvoiceSubmission $submission,
        FatooraClient $zatcaClient,
        Submitter $submitter,
        SubmissionLedger $ledger
    ): FatooraResponse {
        $invoice = $submission->invoice;

        // Issue the document first if it has not been issued.
        //
        // Submitter::generate() is the one place that allocates a counter and
        // fixes a predecessor, under the organization-row lock that also reads
        // the predecessor, and it leaves an already-issued document alone.
        if ($invoice->icv === null || $invoice->hash === null) {
            $submitter->generate($invoice, $submission->org);
            $invoice->refresh();
        }

        // The document that was issued, not a new one: signing again moves the
        // XAdES SigningTime, so a retry would send bytes the archive never held.
        $invoiceXml = (string) $invoice->signed_xml;
        $invoiceHash = (string) $invoice->hash;

        $ledger->transition($submission, 'submitted', 'queue_job');

        // Fire submitted event for real-time tracking
        event(new InvoiceSubmitted($submission->fresh()));

        return $submission->isClearance()
            ? $zatcaClient->clearInvoice($invoiceXml, $invoiceHash, $invoice->id)
            : $zatcaClient->reportInvoice($invoiceXml, $invoiceHash, $invoice->id);
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

        // Update submission
        $submission->update([
            'state' => 'failed',
            'previous_state' => $submission->state,
            'state_changed_at' => now(),
            'last_error_code' => $errorCode->value,
            'last_error' => $e->getMessage(),
            'retry_count' => $submission->retry_count + 1,
            // backoff() is a method, not a property. Reading $this->backoff
            // yields null, null[$i] yields null, and the coalesce then pins
            // every retry at 300s — so the column reports a delay the queue is
            // not using.
            'next_retry_at' => $isRetryable && $this->attempts() < $this->tries
                ? now()->addSeconds($this->retryDelay())
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
     * Log state transition for audit.
     *
     * failed() is called by the queue without dependency injection, so the
     * job keeps its own writer for the two error paths.
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
