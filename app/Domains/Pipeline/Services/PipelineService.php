<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Services;

use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\OfflineFallback;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * One ERP call: create the invoice, sign it, file it with the authority.
 *
 * ERPs integrate against a single endpoint rather than three, so this
 * sequences the steps and owns what happens when one of them fails. Each step
 * belongs to a collaborator:
 *
 *   InvoiceDrafter    payload -> draft invoice with priced lines
 *   Submitter         compliance documents: XML, hash, QR, signature
 *   OfflineFallback   files it, or queues it when ZATCA is unreachable
 *     SubmissionTracker  idempotency, submission record, state machine
 *       FatooraClient    the call to ZATCA
 *   PipelineNotifier  tells the ERP what happened
 *   PipelineResult    shapes the reply
 *
 * The failure rule throughout: once an invoice is issued it stays issued. A
 * failure after that point is reported in `errors` alongside a real invoice,
 * never by discarding one that already carries a compliance hash.
 */
class PipelineService
{
    public function __construct(
        private readonly InvoiceDrafter $drafter,
        private readonly Submitter $compliance,
        private readonly OfflineFallback $submissions,
        private readonly PipelineNotifier $notifier,
        private readonly PipelineResult $result,
    ) {}

    /**
     * @param  array  $data  Validated request payload
     * @param  string|null  $idempotencyKey  Caller's Idempotency-Key, if sent
     */
    public function submitInvoice(
        array $data,
        string $organizationId,
        ?string $branchId = null,
        ?string $idempotencyKey = null,
    ): array {
        $organization = Organization::findOrFail($organizationId);

        $invoice = $this->drafter->draft(
            $data,
            $organizationId,
            $this->resolveBranch($branchId, $organizationId)
        );

        $compliance = $this->generate($invoice, $organization);

        if ($compliance !== []) {
            return $this->result->build($invoice, $compliance, [], null);
        }

        if (! (bool) ($data['auto_submit'] ?? true)) {
            return $this->result->build($invoice, [], [], null);
        }

        return $this->submit($invoice, $idempotencyKey);
    }

    /**
     * Confirm the requested branch belongs to the paying organization.
     *
     * The branch decides which ZATCA certificate signs the invoice, so an
     * unchecked identifier would let one taxpayer issue documents under
     * another's credentials. Looked up through the tenant scope, so a branch
     * belonging to anyone else is simply not found.
     *
     * @throws FatooraException When the branch is not this organization's
     */
    private function resolveBranch(?string $branchId, string $organizationId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        $exists = Branch::where('org_id', $organizationId)
            ->whereKey($branchId)
            ->exists();

        if (! $exists) {
            throw FatooraException::validation(
                "Branch {$branchId} does not belong to this organization."
            );
        }

        return $branchId;
    }

    /**
     * Produce the compliance documents.
     *
     * @return list<string> Errors; empty when the invoice was signed.
     */
    private function generate(Invoice $invoice, Organization $organization): array
    {
        try {
            $this->compliance->generate($invoice, $organization);
            $invoice->refresh();

            $this->notifier->issued($invoice);

            return [];
        } catch (FatooraException $e) {
            Log::error('Pipeline: compliance generation failed', [
                'invoice_id' => $invoice->id,
                'org_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return ['Compliance generation failed: '.$e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('Pipeline: unexpected error during compliance generation', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return ['Unexpected error during compliance generation: '.$e->getMessage()];
        }
    }

    /**
     * File the signed invoice, or queue it if the authority is unreachable.
     *
     * A submission failure leaves the invoice issued. It holds a valid hash and
     * QR, so the offline queue or a later retry can still file it; discarding
     * it would break the hash chain that the next invoice builds on.
     */
    private function submit(Invoice $invoice, ?string $idempotencyKey): array
    {
        try {
            $outcome = $this->submissions->submit($invoice, ['idempotency_key' => $idempotencyKey]);
            $invoice->refresh();

            $warnings = $outcome['warnings'] ?? [];
            $errors = $outcome['errors'] ?? [];

            if ($outcome['success'] ?? false) {
                $this->notifier->accepted($invoice, $warnings);
            } else {
                $this->notifier->rejected($invoice, $errors);
            }

            return $this->result->build($invoice, $errors, $warnings, [
                'submission_id' => $outcome['submission_id'] ?? null,
                'state' => $outcome['state'] ?? null,
                'clearance_status' => $outcome['clearance_status'] ?? null,
                'reporting_status' => $outcome['reporting_status'] ?? null,
            ]);
        } catch (FatooraException $e) {
            Log::warning('Pipeline: ZATCA submission failed, invoice remains issued', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
            ]);

            $invoice->refresh();

            return $this->result->build($invoice, ['ZATCA submission failed: '.$e->getMessage()], [], null);
        } catch (\Throwable $e) {
            Log::error('Pipeline: unexpected error during ZATCA submission', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $invoice->refresh();

            return $this->result->build(
                $invoice,
                ['Unexpected error during ZATCA submission: '.$e->getMessage()],
                [],
                null
            );
        }
    }
}
