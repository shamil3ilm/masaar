<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\IssueOutcome;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Issue an existing draft and file it.
 *
 * This sequence lived as two private methods on PipelineService and was
 * reachable only by drafting an invoice first. The portal needs the same
 * steps on an invoice that already exists, and writing them a second time
 * would mean two implementations of the step that allocates a counter and
 * writes the hash chain.
 *
 * Submission failure never rolls back issuance. The invoice holds a counter
 * and a position in the chain; withdrawing it would leave a gap, which is the
 * one thing the chain cannot have.
 *
 * The error strings are worded exactly as PipelineService worded them: an ERP
 * reads them, and PipelineContractTest pins the 'ZATCA submission failed: '
 * prefix. PipelineController does not parse them - it branches on whether
 * errors is empty and on the status value - so the wording is a promise to
 * integrators rather than to our own transport.
 */
class InvoiceIssuer
{
    public function __construct(
        private readonly Submitter $compliance,
        private readonly OfflineFallback $submissions,
        private readonly IssuanceNotifier $notifier,
    ) {}

    /**
     * $file has no default on purpose. Filing with ZATCA is irreversible, and
     * a default would let a caller that simply forgot to mention it file on
     * live taxpayer data - the very thing PipelineService's auto_submit gate
     * exists to prevent, reintroduced one level down. Callers say what they
     * want, and the language holds them to it.
     */
    public function issue(
        Invoice $invoice,
        Organization $organization,
        bool $file,
        ?string $idempotencyKey = null,
    ): IssueOutcome {
        $failure = $this->generate($invoice, $organization);

        if ($failure !== null) {
            return IssueOutcome::notIssued($failure);
        }

        if (! $file) {
            return IssueOutcome::issuedOnly();
        }

        return $this->file($invoice, $idempotencyKey);
    }

    /**
     * @return string|null The error, or null when the invoice was signed.
     */
    private function generate(Invoice $invoice, Organization $organization): ?string
    {
        try {
            $this->compliance->generate($invoice, $organization);
            $invoice->refresh();

            $this->notifier->issued($invoice);

            return null;
        } catch (FatooraException $e) {
            Log::error('Issuance: compliance generation failed', [
                'invoice_id' => $invoice->id,
                'org_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return 'Compliance generation failed: '.$e->getMessage();
        } catch (\Throwable $e) {
            Log::error('Issuance: unexpected error during compliance generation', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return 'Unexpected error during compliance generation: '.$e->getMessage();
        }
    }

    private function file(Invoice $invoice, ?string $idempotencyKey): IssueOutcome
    {
        try {
            $outcome = $this->submissions->submit($invoice, ['idempotency_key' => $idempotencyKey]);
            $invoice->refresh();

            // The outcome webhook is raised by SubmissionTracker as a state
            // event, for both the inline and queued paths. It was announced
            // here as well, under the same event names but a different payload
            // shape - total against total_amount, type against invoice_type -
            // so a queued submission delivered two webhooks for one outcome and
            // an integrator could not rely on either shape.

            return IssueOutcome::filed(
                $outcome,
                $outcome['errors'] ?? [],
                $outcome['warnings'] ?? [],
            );
        } catch (FatooraException $e) {
            Log::warning('Issuance: ZATCA submission failed, invoice remains issued', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
            ]);

            $invoice->refresh();

            return IssueOutcome::filingFailed('ZATCA submission failed: '.$e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Issuance: unexpected error during ZATCA submission', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $invoice->refresh();

            return IssueOutcome::filingFailed(
                'Unexpected error during ZATCA submission: '.$e->getMessage()
            );
        }
    }
}
