<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Services;

use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\InvoiceIssuer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * One ERP call: create the invoice, sign it, file it with the authority.
 *
 * ERPs integrate against a single endpoint rather than three, so this
 * sequences the steps and owns what happens when one of them fails. Each step
 * belongs to a collaborator:
 *
 *   InvoiceDrafter    payload -> draft invoice with priced lines
 *   InvoiceIssuer     signs the draft, then files it with the authority
 *     Submitter          compliance documents: XML, hash, QR, signature
 *     OfflineFallback    files it, or queues it when ZATCA is unreachable
 *       SubmissionTracker  idempotency, submission record, state machine
 *         FatooraClient      the call to ZATCA
 *     IssuanceNotifier   tells the ERP what happened
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
        private readonly InvoiceIssuer $issuer,
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

        // A retry: answer with what the first call made, and do nothing again.
        // The key only reached filing before, so the retry drafted a second
        // invoice - colliding on the invoice number, or, with a fresh one,
        // billing the sale twice.
        if ($idempotencyKey !== null && ($earlier = $this->drafter->createdUnder($organizationId, $idempotencyKey))) {
            return $this->replay($earlier, $data);
        }

        try {
            $invoice = $this->drafter->draft(
                [...$data, 'idempotency_key' => $idempotencyKey],
                $organizationId,
                $this->resolveBranch($branchId, $organizationId)
            );
        } catch (UniqueConstraintViolationException $e) {
            // Two retries raced past the check above; the unique index let one
            // draft. The other answers with that one.
            $earlier = $idempotencyKey === null ? null : $this->drafter->createdUnder($organizationId, $idempotencyKey);

            if ($earlier === null) {
                throw $e;
            }

            return $this->replay($earlier, $data);
        }

        $outcome = $this->issuer->issue(
            $invoice,
            $organization,
            // The gate an ERP sets to take the invoice now and file it later.
            // It lives here rather than in the issuer because it is a property
            // of this request, not of issuance.
            file: (bool) ($data['auto_submit'] ?? true),
            idempotencyKey: $idempotencyKey,
        );

        if (! $outcome->issued) {
            return $this->result->build($invoice, $outcome->errors, $outcome->warnings, null);
        }

        if (! $outcome->attemptedFiling) {
            return $this->result->build($invoice, [], [], null);
        }

        return $this->result->build(
            $invoice,
            $outcome->errors,
            $outcome->warnings,
            // No submission means the filing threw, so the authority never
            // answered. A four-key block of nulls would read as an answer that
            // said nothing, where the contract says there was no answer at all.
            $outcome->submission === null ? null : [
                'submission_id' => $outcome->submission['submission_id'] ?? null,
                'state' => $outcome->submission['state'] ?? null,
                'clearance_status' => $outcome->submission['clearance_status'] ?? null,
                'reporting_status' => $outcome->submission['reporting_status'] ?? null,
            ]
        );
    }

    /**
     * What the first request with this key produced, as it stands now.
     *
     * A key names one request. Sent with a different invoice it is an
     * integrator's bug, and answering with the other invoice would hide it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function replay(Invoice $invoice, array $data): array
    {
        if ($invoice->invoice_number !== ($data['invoice_number'] ?? null)) {
            throw ValidationException::withMessages([
                'invoice_number' => 'This Idempotency-Key was already used for invoice '.$invoice->invoice_number
                    .'. Send a new key for a new invoice.',
            ]);
        }

        $submission = $invoice->latestSubmission;

        return $this->result->build($invoice, [], [], $submission === null ? null : [
            'submission_id' => $submission->id,
            'state' => $submission->state,
            'clearance_status' => $submission->clearance_status,
            'reporting_status' => $submission->reporting_status,
        ]);
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
}
