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

            $this->discardRolledBackValues($invoice);

            return 'Compliance generation failed: '.$e->getMessage();
        } catch (\Throwable $e) {
            Log::error('Issuance: unexpected error during compliance generation', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $this->discardRolledBackValues($invoice);

            // The message is logged above and kept out of the answer: it goes
            // to the ERP and the portal, and an unexpected failure's own words
            // can be SQL or a path on this server.
            return 'Unexpected error during compliance generation. The details are logged; retry, and contact support if it persists.';
        }
    }

    /**
     * Put the caller's invoice back to what the database holds.
     *
     * Submitter::generate sets icv, hash, qr_code, signed_xml and status on
     * the model inside its transaction. A throw after that rolls the database
     * back and leaves the object holding values that were never kept - and
     * the caller reads this same instance: PipelineService hands it to
     * PipelineResult::build, which would report status "issued" and a hash
     * that exists nowhere to an ERP whose invoice is still a draft here.
     *
     * rescue() because a failure to refresh must not replace the error that
     * brought us here. Same reasoning as SubmissionLedger::reportUnrecorded.
     */
    private function discardRolledBackValues(Invoice $invoice): void
    {
        rescue(fn () => $invoice->refresh(), report: false);
    }

    /**
     * File an invoice that is already issued.
     *
     * For a retry that finds the invoice issued and not filed. Under the same
     * key, a submission that was made answers with what it got; one that was
     * never made - refused before it began, say for want of a certificate - is
     * made now.
     */
    public function file(Invoice $invoice, ?string $idempotencyKey): IssueOutcome
    {
        try {
            // A simplified invoice is queued. A standard one is not.
            //
            // This is the Implementation Resolution's distinction, not a
            // performance choice. Clause Second has the authority stamp a
            // standard invoice and notify the issuer "prior to sharing them
            // with the customers", so until clearance returns the seller may
            // not hand the document over - and the caller has nothing to hand
            // over, because cleared_xml is what print.blade.php checks before
            // it will render the copy. Queueing that would make every B2B
            // invoice unshareable until a worker drained the queue, with
            // polling as the only way to find out.
            //
            // A simplified invoice is the opposite case. Clause Third has the
            // seller present the printed copy immediately and the same clause
            // allows twenty-four hours to report it. The receipt is complete
            // the moment it is issued; the authority hearing about it later is
            // the shape the rule describes, and that window is exactly the
            // latitude a queue needs.
            //
            // So the three zatca-submissions workers now have the B2C traffic
            // to do, and with them the job's retries - which never ran,
            // because the job was never dispatched.
            $outcome = $this->submissions->submit($invoice, [
                'idempotency_key' => $idempotencyKey,
                'async' => ! $invoice->requiresClearance(),
            ]);
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
                'Unexpected error during ZATCA submission. The details are logged; the invoice remains issued.'
            );
        }
    }
}
