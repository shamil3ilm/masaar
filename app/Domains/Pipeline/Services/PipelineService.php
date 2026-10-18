<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Services;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IdempotentDraft;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Validation\ValidationException;

/**
 * One ERP call: create the invoice, sign it, file it with the authority.
 *
 * ERPs integrate against a single endpoint rather than three, so this
 * sequences the steps and owns what happens when one of them fails. Each step
 * belongs to a collaborator:
 *
 *   InvoiceDrafter    payload -> draft invoice with priced lines, mapped by
 *                     the country's InvoiceRules, once per Idempotency-Key
 *                     (IdempotentDraft)
 *   ComplianceEngine  issues the draft in the organization's jurisdiction,
 *                     then files it with the authority (see
 *                     docs/architecture/JURISDICTION-ROUTING.md)
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
        private readonly IdempotentDraft $once,
        private readonly ComplianceEngine $engine,
        private readonly PipelineResult $result,
        private readonly FeatureOverrides $overrides,
    ) {}

    /**
     * Batch submission is every customer's unless a platform admin switched
     * it off for this one (Feature::BatchSubmission). No plan restricts it
     * while allowances are unlimited.
     *
     * @throws ComplianceException AUTH_FEATURE_SWITCHED_OFF
     */
    public function assertMayBatch(string $organizationId): void
    {
        $this->overrides->assertNotSwitchedOff(Organization::findOrFail($organizationId), Feature::BatchSubmission);
    }

    /**
     * @param  array  $data  Validated request payload
     * @param  string|null  $idempotencyKey  Caller's Idempotency-Key, if sent
     *
     * @throws JurisdictionUnavailableException for a country this deployment does not issue in
     */
    public function submitInvoice(
        array $data,
        string $organizationId,
        ?string $branchId = null,
        ?string $idempotencyKey = null,
    ): array {
        $organization = Organization::findOrFail($organizationId);

        // A country this deployment does not issue in is refused before
        // anything is drafted, rather than leaving a draft nothing can issue.
        // PipelineSubmitRequest has refused it already; a caller that comes
        // here directly is refused the same way.
        $rules = $this->engine->jurisdictionFor($organization)->invoiceRules();

        // A platform admin can switch the pipeline off for this organization
        // alone (docs/architecture/ACCESS.md): refused, as the country is,
        // before anything is drafted.
        $this->overrides->assertNotSwitchedOff($organization, Feature::ErpPipeline);

        // A retry: answer with what the first call made, and do nothing again.
        // The key only reached filing before, so the retry drafted a second
        // invoice - colliding on the invoice number, or, with a fresh one,
        // billing the sale twice.
        if ($earlier = $this->once->earlier($organizationId, $idempotencyKey, $data)) {
            return $this->replay($earlier, $data, $organization, $idempotencyKey);
        }

        // Sent with no number and no key, the ERP's own reference is what
        // names the document. The number used to be made from it, so a retry
        // collided with the first call on the unique index; the number now
        // comes from the sequence at issue, and without this a retry drafted
        // a second invoice and billed the sale twice.
        if ($idempotencyKey === null
            && blank($data['invoice_number'] ?? null)
            && filled($data['erp_reference_id'] ?? null)
            && ($earlier = $this->drafter->createdFor($organizationId, (string) $data['erp_reference_id']))) {
            return $this->replay($earlier, $data, $organization, null);
        }

        // Drafted as the country's rules map the request, with the ERP's
        // reference and key beside it: a country's draft names only its
        // invoice's own fields.
        $invoice = $this->once->draft(
            [
                ...$rules->draft($data),
                'erp_reference_id' => $data['erp_reference_id'] ?? null,
            ],
            $organizationId,
            $this->resolveBranch($branchId, $organizationId),
            $rules->countryFields($data),
            $idempotencyKey,
        );

        // Two retries raced past the check above; the unique index let one
        // draft. The other answers with that one.
        if (! $invoice->wasRecentlyCreated) {
            return $this->replay($invoice, $data, $organization, $idempotencyKey);
        }

        return $this->issue($invoice, $organization, $data, $idempotencyKey);
    }

    /**
     * Issue a draft, file it unless asked not to, and shape the answer.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function issue(Invoice $invoice, Organization $organization, array $data, ?string $idempotencyKey): array
    {
        return $this->answer($invoice, $this->engine->issue($invoice, $organization, $this->options($data, $idempotencyKey)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function options(array $data, ?string $idempotencyKey): IssueOptions
    {
        return new IssueOptions(
            // The gate an ERP sets to take the invoice now and file it later.
            // It lives here rather than in the issuer because it is a property
            // of this request, not of issuance.
            file: (bool) ($data['auto_submit'] ?? true),
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(Invoice $invoice, IssueOutcome $outcome): array
    {
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
     * A key names one request, so the request is checked to be the first
     * one's (IdempotentDraft::assertSameRequest) before anything is done.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function replay(Invoice $invoice, array $data, Organization $organization, ?string $idempotencyKey): array
    {
        $this->once->assertSameRequest($invoice, $data);

        // The first call drafted and then failed to issue. Answering with the
        // stored draft and no errors reported a clean success for an invoice
        // with no counter, hash or QR, and the ERP marked the sale done. A
        // retry is how it recovers, so it carries on from the draft.
        if ($invoice->status === InvoiceStatus::Draft) {
            return $this->issue($invoice, $organization, $data, $idempotencyKey);
        }

        // Issued and not filed - the filing failed, or was refused before it
        // began. This answered with no errors whenever the invoice was past
        // draft, so a 207 on the first call became a 201 on the retry and the
        // ERP marked an unfiled invoice done. The jurisdiction files it now if
        // that is still owed and asked for; null is nothing to do, and the
        // answer comes from the record below.
        if ($invoice->status === InvoiceStatus::Issued
            && ($outcome = $this->engine->resume($invoice, $organization, $this->options($data, $idempotencyKey))) !== null) {
            return $this->answer($invoice, $outcome);
        }

        $submission = $invoice->latestSubmission;

        // A rejection is the answer, and it is not a success.
        $errors = $invoice->status === InvoiceStatus::Rejected
            ? ['ZATCA rejected this invoice: '.($submission?->last_error ?? 'see zatca_response').' Issue a credit note to correct it.']
            : [];

        return $this->result->build($invoice, $errors, [], $submission === null ? null : [
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
     * @throws ComplianceException When the branch is not this organization's
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
            throw new ComplianceException(
                "Branch {$branchId} does not belong to this organization.",
                ErrorCode::VAL_INVALID_FORMAT,
            );
        }

        return $branchId;
    }
}
