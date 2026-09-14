<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Events\BaseInvoiceEvent;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\SubmissionIdempotency;
use App\Domains\Licensing\Services\UsageMeteringService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where a submission is, and what ZATCA said about it.
 *
 * The synchronous tracker and the queued job both send documents. Both take a
 * submission, move it through its states and record the authority's answer
 * through here, so the two paths cannot drift apart on either.
 */
class SubmissionLedger
{
    public function __construct(
        private readonly ClearanceState $clearanceState,
        private readonly UsageMeteringService $usage,
    ) {}

    /**
     * Take a submission for sending, or null when it is not in a state in $from.
     *
     * The row is locked and read again before its state is trusted, so two
     * callers holding stale copies — a duplicate job, a double-clicked retry —
     * cannot both take it: the second sees the first one's transition. $admit
     * runs against the locked row for any further check and refuses by
     * throwing.
     *
     * @param  list<string>  $from
     * @param  (Closure(InvoiceSubmission): void)|null  $admit
     */
    public function claim(InvoiceSubmission $submission, array $from, string $trigger, ?Closure $admit = null): ?InvoiceSubmission
    {
        return DB::transaction(function () use ($submission, $from, $trigger, $admit): ?InvoiceSubmission {
            $locked = InvoiceSubmission::query()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! in_array($locked->state, $from, true)) {
                return null;
            }

            if ($admit !== null) {
                $admit($locked);
            }

            $this->transition($locked, 'pending_submission', $trigger);

            return $locked;
        });
    }

    /**
     * Move a submission to a new state and log the move.
     *
     * @param  array<string, mixed>  $context
     */
    public function transition(InvoiceSubmission $submission, string $to, string $trigger, array $context = []): void
    {
        $from = $submission->state;

        $submission->update([
            'state' => $to,
            'previous_state' => $from,
            'state_changed_at' => now(),
            'submitted_at' => $to === 'submitted' ? now() : $submission->submitted_at,
        ]);

        $this->log($submission, $from, $to, $trigger, $context);
    }

    /**
     * Record ZATCA's answer and return the state it produced.
     *
     * Metering, the submission row, its idempotency record and the state log
     * describe one answer, so they are written in one transaction.
     *
     * The answer is already final at the authority. A local failure here must
     * therefore not become a failure of the submission: a failed submission
     * is retried, and the retry would send a document ZATCA has accepted. The
     * submission stays 'submitted', a state nothing sends from, the failure is
     * logged for reconciliation, and null is returned.
     */
    public function recordResponse(InvoiceSubmission $submission, FatooraResponse $response): ?string
    {
        try {
            $state = DB::transaction(fn (): string => $this->writeResponse($submission, $response));
        } catch (Throwable $e) {
            $this->reportUnrecorded($submission, $response, $e);

            return null;
        }

        $this->announce($submission, $state, $response);

        return $state;
    }

    /**
     * Append a row to the submission's state history.
     *
     * @param  array<string, mixed>  $context
     */
    public function log(
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

    private function writeResponse(InvoiceSubmission $submission, FatooraResponse $response): string
    {
        [$state, $clearance] = $this->outcome($submission, $response);

        $this->usage->recordSubmissionOutcome(
            (string) $submission->org_id,
            $state,
            (float) ($submission->invoice?->total ?? 0)
        );

        $from = $submission->state;
        $warnings = $response->validationResults['warnings'] ?? $response->warningMessages;
        $errors = $response->validationResults['errors'] ?? $response->errorMessages;

        $submission->update([
            'state' => $state,
            'previous_state' => $from,
            'state_changed_at' => now(),
            'zatca_uuid' => $response->validationResults['invoiceUuid'] ?? null,
            'invoice_hash' => $response->validationResults['invoiceHash'] ?? null,
            'clearance_status' => $response->clearanceStatus,
            'clearance_state' => $clearance['state'],
            // Only a terminal clearance is confirmed; a document still awaiting
            // ZATCA's decision has neither a confirmation nor a completion time.
            'cleared_at' => $clearance['is_terminal'] ? now() : null,
            'reporting_status' => $response->reportingStatus,
            'zatca_warnings' => ! empty($warnings) ? $warnings : null,
            'zatca_errors' => ! empty($errors) ? $errors : null,
            'completed_at' => $clearance['is_terminal'] ? now() : null,
        ]);

        $this->recordIdempotency($submission, $response);

        $this->log($submission, $from, $state, 'zatca', [
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
        ]);

        return $state;
    }

    /**
     * The submission state an answer produces, and ZATCA's own clearance state.
     *
     * A 200 does not mean cleared: for a B2B document "REPORTED" means received
     * and not yet cleared, so the state comes from what ZATCA returned.
     *
     * @return array{0: string, 1: array{state: string, is_terminal: bool}}
     */
    private function outcome(InvoiceSubmission $submission, FatooraResponse $response): array
    {
        $clearance = $this->clearanceState->parseResponse([
            'clearanceStatus' => $response->clearanceStatus,
            'reportingStatus' => $response->reportingStatus,
            'validationResults' => $response->validationResults,
        ], isSimplified: ! $submission->isClearance());

        $state = match (true) {
            ! $response->success => 'rejected',
            $response->hasWarnings() => 'warning',
            default => ClearanceState::submissionState($clearance['state']),
        };

        return [$state, $clearance];
    }

    private function recordIdempotency(InvoiceSubmission $submission, FatooraResponse $response): void
    {
        SubmissionIdempotency::where('id', $submission->idempotency_id)->update([
            'status' => $response->success ? 'completed' : 'failed',
            'http_status_code' => $response->success ? 200 : 422,
            'response_body' => [
                'clearanceStatus' => $response->clearanceStatus,
                'reportingStatus' => $response->reportingStatus,
                'validationStatus' => $response->validationStatus,
                'validationResults' => $response->validationResults,
                'warningMessages' => $response->warningMessages,
                'errorMessages' => $response->errorMessages,
            ],
            'clearance_status' => $response->clearanceStatus,
            'completed_at' => now(),
        ]);
    }

    /**
     * Raise the outcome event once the answer is committed.
     *
     * A listener failing is logged rather than thrown, because the caller's
     * error handling would otherwise mark an accepted submission failed.
     */
    private function announce(InvoiceSubmission $submission, string $state, FatooraResponse $response): void
    {
        try {
            BaseInvoiceEvent::raise($submission->fresh(), $state, [
                'clearance_status' => $response->clearanceStatus,
                'reporting_status' => $response->reportingStatus,
            ]);
        } catch (Throwable $e) {
            Log::error('Submission outcome recorded but its event failed', [
                'submission_id' => $submission->id,
                'state' => $state,
                'exception' => $e,
            ]);
        }
    }

    private function reportUnrecorded(InvoiceSubmission $submission, FatooraResponse $response, Throwable $e): void
    {
        Log::critical('ZATCA answered a submission but the answer could not be recorded', [
            'submission_id' => $submission->id,
            'invoice_id' => $submission->invoice_id,
            'success' => $response->success,
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
            'exception' => $e,
        ]);

        // The rolled-back update left the model holding values the database
        // never kept; callers read the submission after this returns.
        rescue(fn () => $submission->refresh(), report: false);
    }
}
