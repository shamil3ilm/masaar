<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Invoice\Support\ShareAllocation;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One sale billed in two shares: the patient's (a co-payment or deductible)
 * and the insurer's. Each share is an invoice in its own right - its own
 * number, counter and chain, cleared or reported on its own - and both name
 * the split (InsuranceSplit), so either finds the other.
 *
 * The sale is entered once and divided by ShareAllocation. Which kind of
 * document each share is, and how a line is taxed once the insurer is its
 * buyer, are the country's (SplitBilling); each share is then checked by the
 * country's invoice rules as if it had been typed by hand (CountryDraft).
 *
 * The insurer's references go where its invoice has a place for them: the
 * claim or approval number as the order reference (BT-13), the policy as the
 * contract reference (BT-12), and the member's number as the buyer's own
 * reference (BT-10) - an identifier the buyer assigns, which is what a
 * member number is (EN 16931-1, BT-10, BT-12, BT-13).
 *
 * Once the insurer has decided, settle() records it: what it declined is
 * credited on its invoice and billed to the patient on a new invoice, the
 * two documents stating the same items for the same amounts.
 */
class InsuranceSplits
{
    /** The reason the authority lists that fits an insurer declining its share (KSA-10's list, AdjustmentReason). */
    public const DECLINED_REASON = 'Cancellation or suspension of the supplies after its occurrence either wholly or partially';

    /** The sale's fields that are about the split, not either document. */
    private const SPLIT_ONLY = ['split', 'invoice_number', 'discount_amount', 'charges', 'prepayments'];

    /** The patient's buyer details, which the insurer's document does not carry. */
    private const BUYER = ['customer_id', 'buyer_name', 'buyer_name_ar', 'buyer_vat_number', 'buyer_id', 'buyer_id_scheme', 'buyer_address', 'payment_terms'];

    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly InvoiceDrafter $drafter,
        private readonly ComplianceEngine $engine,
        private readonly Catalogue $catalogue,
        private readonly AdjustmentDraft $adjustments,
    ) {}

    /**
     * Why the organization's country bills no sale in two shares; null when it does.
     */
    public function unavailable(?Organization $organization): ?string
    {
        return $this->jurisdictions->forRules($organization)->splitBilling()->unavailable();
    }

    /** The organization's split with this id, its invoices and their lines loaded. */
    public function find(string $organizationId, string $id): ?InsuranceSplit
    {
        return InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()
            ->where('org_id', $organizationId)
            ->with('invoices.lines')
            ->find($id));
    }

    /**
     * The split made under this key earlier, if any.
     */
    public function createdUnder(string $organizationId, ?string $key): ?InsuranceSplit
    {
        return $key === null ? null : InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()
            ->where('org_id', $organizationId)->where('idempotency_key', $key)->first());
    }

    /**
     * Draft both shares of a sale, linked, or neither.
     *
     * @param  array<string, mixed>  $sale  the sale as validated, its lines naming their payer where split by line
     * @param  array<string, mixed>  $split  method, percent or amount, the insurer, and its references
     *
     * @throws ValidationException naming the share and field refused
     */
    public function draft(Organization $organization, array $sale, array $split, ?string $branchId = null, ?string $tillId = null, ?string $key = null): InsuranceSplit
    {
        if ($earlier = $this->createdUnder((string) $organization->id, $key)) {
            return $earlier;
        }

        $jurisdiction = $this->jurisdictions->forRules($organization);
        $port = $jurisdiction->splitBilling();

        if (($reason = $port->unavailable()) !== null) {
            throw ValidationException::withMessages(['split' => $reason]);
        }

        $method = (string) $split['method'];
        $value = $method === 'percent' ? ($split['percent'] ?? null) : ($method === 'amount' ? ($split['amount'] ?? null) : null);
        $inclusive = array_key_exists('prices_include_vat', $sale) && $sale['prices_include_vat'] !== null
            ? (bool) $sale['prices_include_vat'] : (bool) $organization->prices_include_vat;
        $shares = ShareAllocation::divide((array) $sale['lines'], $method, $value === null ? null : (string) $value);
        $insurer = $this->insurer((string) $organization->id, $split);

        $header = [...array_diff_key($sale, array_flip(self::SPLIT_ONLY)), 'prices_include_vat' => $inclusive, 'document_type' => DocumentType::Invoice->value];
        $patient = CountryDraft::check($jurisdiction->invoiceRules(), [
            ...$header,
            'type' => $port->patientType(),
            'lines' => $shares[ShareAllocation::PATIENT],
        ], 'patient');
        $insurerDraft = CountryDraft::check($jurisdiction->invoiceRules(), [
            ...array_diff_key($header, array_flip(self::BUYER)),
            ...$insurer['buyer'],
            'type' => $port->insurerType(),
            'order_reference' => $split['claim_reference'] ?? null,
            'contract_reference' => $split['policy_number'] ?? null,
            'buyer_reference' => $split['member_id'] ?? null,
            'lines' => array_map(fn (array $line): array => $port->insurerLine($line), $shares[ShareAllocation::INSURER]),
        ], 'insurer');

        $totals = InvoiceTotals::of(array_map(fn (array $line): array => array_diff_key($line, ['payer' => true]), (array) $sale['lines']), '0', $inclusive);

        return DB::transaction(function () use ($organization, $branchId, $tillId, $key, $method, $split, $insurer, $totals, $patient, $insurerDraft, $sale): InsuranceSplit {
            $record = InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->create([
                'org_id' => $organization->id,
                'branch_id' => $branchId,
                'insurer_customer_id' => $insurer['customer_id'],
                'method' => $method,
                'patient_percent' => $method === 'percent' ? $split['percent'] : null,
                'patient_amount' => $method === 'amount' ? $split['amount'] : null,
                'currency' => (string) ($sale['currency'] ?? $this->jurisdictions->forRules($organization)->currency()),
                'sale_subtotal' => $totals->subtotal,
                'sale_tax' => $totals->tax,
                'sale_total' => $totals->total,
                'policy_number' => $split['policy_number'] ?? null,
                'member_id' => $split['member_id'] ?? null,
                'claim_reference' => $split['claim_reference'] ?? null,
                'status' => InsuranceSplit::BILLED,
                'idempotency_key' => $key,
            ]));

            foreach ([InsuranceSplit::PATIENT => $patient, InsuranceSplit::INSURER => $insurerDraft] as $role => $share) {
                $this->drafter->draft(
                    [...$share['draft'], 'insurance_split_id' => $record->id, 'split_role' => $role],
                    (string) $organization->id, $branchId, $share['countryFields'], $tillId,
                );
            }

            return $record->load('invoices.lines');
        });
    }

    /**
     * Issue both shares: the patient's first, so the insurer's names it.
     * The insurer's is left a draft when the patient's could not be issued,
     * so a retry carries on from where this stopped.
     *
     * @return array{patient: IssueOutcome, insurer: IssueOutcome|null}
     */
    public function issue(InsuranceSplit $split, Organization $organization, IssueOptions $options): array
    {
        $split->load('invoices');
        $outcomes = [];

        foreach ([InsuranceSplit::PATIENT, InsuranceSplit::INSURER] as $role) {
            $invoice = $split->share($role);

            if ($invoice === null) {
                $outcomes[$role] = null;

                continue;
            }

            $outcomes[$role] = $invoice->status === InvoiceStatus::Draft
                ? $this->engine->issue($invoice, $organization, $options)
                : ($this->engine->resume($invoice, $organization, $options) ?? $this->issued());

            if (! $outcomes[$role]->issued) {
                $outcomes[InsuranceSplit::INSURER] ??= null;

                break;
            }
        }

        return ['patient' => $outcomes[InsuranceSplit::PATIENT], 'insurer' => $outcomes[InsuranceSplit::INSURER] ?? null];
    }

    /**
     * The insurer's decision: it pays $approved of its invoice (with VAT).
     * What it declined is credited on its invoice and billed to the patient
     * on a new invoice, both issued; the split is settled. Approving it all
     * settles it with neither.
     *
     * The declined part is divided over the insurer's lines in proportion,
     * as the patient's fixed share is (ShareAllocation), so the credit note
     * and the patient's new invoice state the same items for the same
     * prices. Each keeps the insurer invoice's VAT treatment of its lines.
     *
     * @return array{credit: IssueOutcome|null, rebill: IssueOutcome|null}
     *
     * @throws ValidationException when the split cannot be settled so
     */
    public function settle(InsuranceSplit $split, Organization $organization, string $approved, ?string $reason, IssueOptions $options): array
    {
        $split->load('invoices.lines', 'invoices.charges');
        $insurer = $split->share(InsuranceSplit::INSURER);
        $patient = $split->share(InsuranceSplit::PATIENT);

        if ($split->status !== InsuranceSplit::BILLED) {
            throw ValidationException::withMessages(['approved' => __('The insurer\'s decision on this sale is already recorded.')]);
        }

        if ($insurer === null || $patient === null || ! in_array($insurer->status, [InvoiceStatus::Issued, InvoiceStatus::Submitted, InvoiceStatus::Accepted], true)) {
            throw ValidationException::withMessages(['approved' => __('Issue both shares before recording the insurer\'s decision.')]);
        }

        if (! is_numeric($approved) || bccomp($approved, '0', 2) < 0 || bccomp($approved, (string) $insurer->total, 2) > 0) {
            throw ValidationException::withMessages(['approved' => __('The approved amount is between 0 and the insurer\'s invoice total, :total.', ['total' => $insurer->total])]);
        }

        $declined = bcsub((string) $insurer->total, $approved, 2);

        if (bccomp($declined, '0', 2) === 0) {
            $this->settled($split, $approved, null, null);

            return ['credit' => null, 'rebill' => null];
        }

        $jurisdiction = $this->jurisdictions->forRules($organization);
        $lines = $this->declinedLines($insurer, $declined);
        $reason = filled($reason) ? (string) $reason : (string) AdjustmentReason::bilingual(self::DECLINED_REASON);

        $credit = CountryDraft::check($jurisdiction->invoiceRules(), [
            ...$this->adjustments->fields($insurer, DocumentType::CreditNote),
            'issue_date' => $this->today($jurisdiction),
            'adjustment_reason' => $reason,
            'lines' => $lines,
            'discount_amount' => null,
            'prices_include_vat' => (bool) $insurer->prices_include_vat,
        ], 'credit');
        $rebill = CountryDraft::check($jurisdiction->invoiceRules(), [
            ...$this->patientHeader($patient),
            'issue_date' => $this->today($jurisdiction),
            'lines' => $lines,
            'prices_include_vat' => (bool) $insurer->prices_include_vat,
        ], 'rebill');

        [$note, $invoice] = DB::transaction(function () use ($split, $credit, $rebill): array {
            $note = $this->drafter->draft($credit['draft'], (string) $split->org_id, $split->branch_id, $credit['countryFields']);
            $invoice = $this->drafter->draft(
                [...$rebill['draft'], 'insurance_split_id' => $split->id, 'split_role' => InsuranceSplit::REBILL],
                (string) $split->org_id, $split->branch_id, $rebill['countryFields'],
            );

            return [$note, $invoice];
        });

        $this->settled($split, $approved, $note, $invoice);

        $creditOutcome = $this->engine->issue($note, $organization, $options);
        $rebillOutcome = $creditOutcome->issued ? $this->engine->issue($invoice, $organization, $options) : null;

        return ['credit' => $creditOutcome, 'rebill' => $rebillOutcome];
    }

    /**
     * The insurer's lines for the part it declined: all of them when it
     * declined everything, else that part spread over them in proportion.
     * Prices as the invoice was entered: with VAT in them, or the declined
     * part taken net of VAT in the invoice's own proportion.
     *
     * @return list<array<string, mixed>>
     */
    private function declinedLines(Invoice $insurer, string $declined): array
    {
        $inclusive = (bool) $insurer->prices_include_vat;
        $lines = $insurer->lines->map(fn ($line): array => [
            'description' => (string) $line->description,
            'description_ar' => (string) $line->description_ar,
            'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
            'unit_price' => $line->priceAsEntered($inclusive),
            'unit_code' => (string) $line->unit_code,
            'tax_category' => (string) $line->tax_category,
            'tax_rate' => (string) (float) $line->tax_rate,
            'exempt_code' => $line->exempt_code,
            'exempt_reason' => $line->exempt_reason,
        ])->map(fn (array $line): array => array_filter($line, fn ($value): bool => $value !== null && $value !== ''))->values()->all();

        if (bccomp($declined, (string) $insurer->total, 2) === 0) {
            return $lines;
        }

        // The declined part as entered prices state it.
        $entered = $inclusive ? $declined : bcdiv(bcmul($declined, (string) $insurer->subtotal, 6), (string) $insurer->total, 2);

        return ShareAllocation::divide($lines, 'amount', $entered)[ShareAllocation::PATIENT];
    }

    /**
     * The patient's buyer details as their share named them.
     *
     * @return array<string, mixed>
     */
    private function patientHeader(Invoice $patient): array
    {
        return array_filter([
            'type' => $patient->type->value,
            'document_type' => DocumentType::Invoice->value,
            'currency' => (string) $patient->currency,
            'exchange_rate' => $patient->exchange_rate === null ? null : (string) (float) $patient->exchange_rate,
            'payment_means_code' => $patient->payment_means_code,
            'buyer_name' => $patient->buyer_name,
            'buyer_name_ar' => $patient->buyer_name_ar,
            'buyer_vat_number' => $patient->buyer_vat_number,
            'buyer_id' => $patient->buyer_id,
            'buyer_id_scheme' => $patient->buyer_id_scheme,
            'buyer_address' => $patient->buyer_address,
            'supply_date' => $patient->supply_date?->toDateString(),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function settled(InsuranceSplit $split, string $approved, ?Invoice $note, ?Invoice $invoice): void
    {
        $split->forceFill([
            'status' => InsuranceSplit::SETTLED,
            'insurer_approved' => $approved,
            'credit_note_id' => $note?->id,
            'rebill_invoice_id' => $invoice?->id,
            'settled_at' => now(),
            'settled_by' => auth()->id(),
        ])->save();
    }

    /**
     * The insurer the request names: a saved insurer, or one given in full.
     *
     * @param  array<string, mixed>  $split
     * @return array{customer_id: string|null, buyer: array<string, mixed>}
     */
    private function insurer(string $organizationId, array $split): array
    {
        $id = $split['insurer_customer_id'] ?? null;

        if (is_string($id)) {
            $customer = $this->catalogue->customer($organizationId, $id);

            if (! $customer instanceof Customer || ! $customer->is_insurer) {
                throw ValidationException::withMessages(['split.insurer_customer_id' => __('Choose one of your customers marked as an insurer.')]);
            }

            return ['customer_id' => (string) $customer->id, 'buyer' => [
                'customer_id' => (string) $customer->id,
                'buyer_name' => $customer->name,
                'buyer_name_ar' => $customer->name_ar,
                'buyer_vat_number' => $customer->vat_number,
                'buyer_address' => array_filter(array_combine(Customer::ADDRESS, array_map(fn (string $key) => $customer->{$key}, Customer::ADDRESS)), 'filled') ?: null,
                ...array_filter(['buyer_id' => $customer->buyer_id, 'buyer_id_scheme' => $customer->buyer_id_scheme, 'payment_terms' => $customer->payment_terms], 'filled'),
            ]];
        }

        $given = (array) ($split['insurer'] ?? []);

        return ['customer_id' => null, 'buyer' => array_filter([
            'buyer_name' => $given['name'] ?? null,
            'buyer_name_ar' => $given['name_ar'] ?? null,
            'buyer_vat_number' => $given['vat_number'] ?? null,
            'buyer_id' => $given['buyer_id'] ?? null,
            'buyer_id_scheme' => $given['buyer_id_scheme'] ?? null,
            'buyer_address' => $given['address'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '')];
    }

    private function today(Jurisdiction $jurisdiction): string
    {
        return now($jurisdiction->timezone())->toDateString();
    }

    /** An invoice issued earlier, with nothing left to file. */
    private function issued(): IssueOutcome
    {
        return IssueOutcome::issuedOnly();
    }
}
