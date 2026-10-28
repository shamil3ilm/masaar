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
use App\Domains\Invoice\Exceptions\IdempotencyKeyReusedException;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Invoice\Support\ShareAllocation;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A sale an insurer pays for, wholly or in part, billed as ZATCA's General
 * VAT Guideline Healthcare (January 2023) has it.
 *
 * One supply, one recipient, one invoice in the recipient's name (§5.2,
 * p24: "Private Healthcare Providers should issue invoices in the name of
 * the recipient of the healthcare services. A tax invoice cannot be issued
 * showing the name of insurer as recipient, if it is not the customer").
 * Who the recipient is follows the contract (§5.1, pp20-22):
 *
 *   patient  the default. "It will be presumed that the individual is the
 *            recipient of healthcare services and associated goods, unless
 *            this is expressly evidenced otherwise. This is the case even
 *            where the provider of the healthcare issues the invoice to a
 *            third party, or it is the third party who is responsible for
 *            paying" (p21). The invoice names the patient and states what is
 *            "for the account of" the insurer and of the patient (§5.2 p24;
 *            Example 10 pp22-23, a patient paying what the policy does not
 *            cover); the insurer is sent a copy and does not deduct its VAT
 *            (p25). A citizen's private healthcare is zero-rated on it with
 *            their national ID, whoever pays (§5.3.3, p26).
 *   insurer  where the provider contracts with the insurer, which instructs
 *            it, and the patient "has no contractual rights against the
 *            centre ... nor any obligation to pay" (Example 11, p23): a tax
 *            invoice to the insurer, naming the patient (p24), which the
 *            insurer deducts. A patient who pays a share has an obligation
 *            to pay, so such a sale is the patient's.
 *
 * Which kind of document that is, and how a line is taxed for the insurer,
 * are the country's (SplitBilling); the invoice is checked by the country's
 * rules as one typed by hand would be (CountryDraft). The insurer's
 * references go on its invoice as the claim (BT-13), the policy (BT-12) and
 * the member (BT-10), and on the patient's in its note.
 *
 * The insurer's decision is settle()'s (§5.3.2, p25).
 */
class InsuranceSplits
{
    /** KSA-10's reason for care the insurer does not cover (AdjustmentReason). */
    public const NOT_COVERED_REASON = 'Cancellation or suspension of the supplies after its occurrence either wholly or partially';

    /** KSA-10's reason for the price the insurer agreed (Example 12, p26). */
    public const PRICE_REASON = 'Amendment of the supply value which is pre-agreed upon between the supplier and consumer';

    /** The sale's fields that are about the split, not the invoice. */
    private const SPLIT_ONLY = ['split', 'invoice_number', 'discount_amount', 'charges', 'prepayments'];

    /** The patient's buyer details, which the insurer's invoice does not carry. */
    private const BUYER = ['customer_id', 'buyer_name', 'buyer_name_ar', 'buyer_vat_number', 'buyer_id', 'buyer_id_scheme', 'buyer_address', 'payment_terms'];

    /** A line's VAT treatment. */
    private const TREATMENT = ['tax_category', 'tax_rate', 'exempt_code', 'exempt_reason'];

    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly InvoiceDrafter $drafter,
        private readonly ComplianceEngine $engine,
        private readonly Catalogue $catalogue,
        private readonly AdjustmentDraft $adjustments,
    ) {}

    /** Why the organization's country bills no insurer this way; null when it does. */
    public function unavailable(?Organization $organization): ?string
    {
        return $this->jurisdictions->forRules($organization)->splitBilling()->unavailable();
    }

    /** The organization's split with this id, its invoices and their lines loaded. */
    public function find(string $organizationId, string $id): ?InsuranceSplit
    {
        return InsuranceSplit::acrossTenants()
            ->where('org_id', $organizationId)
            ->with(['invoices' => fn ($invoices) => $invoices->acrossTenants(), 'invoices.lines'])
            ->find($id);
    }

    /**
     * The split made under this key earlier, if any, checked to be this
     * request's: the same key with a different body is refused, as an
     * invoice's is (IdempotentDraft).
     *
     * @param  array<string, mixed>|null  $request  The request as validated
     *
     * @throws IdempotencyKeyReusedException
     */
    public function createdUnder(string $organizationId, ?string $key, ?array $request = null): ?InsuranceSplit
    {
        $earlier = $key === null ? null : InsuranceSplit::acrossTenants()
            ->where('org_id', $organizationId)->where('idempotency_key', $key)->first();

        if ($earlier !== null && $request !== null && $earlier->idempotency_hash !== null
            && ! hash_equals($earlier->idempotency_hash, IdempotentDraft::fingerprint($request))) {
            throw IdempotencyKeyReusedException::for(null);
        }

        return $earlier;
    }

    /**
     * Draft the sale's invoice to its recipient, with the split beside it.
     *
     * @param  array<string, mixed>  $sale  the sale as validated, the patient its buyer, its lines naming their payer where split by line
     * @param  array<string, mixed>  $split  the recipient, the patient's share, the insurer and its references
     * @param  array<string, mixed>|null  $request  the request as validated, fingerprinted with the key
     *
     * @throws ValidationException naming the field refused
     */
    public function draft(Organization $organization, array $sale, array $split, ?string $branchId = null, ?string $tillId = null, ?string $key = null, ?array $request = null): InsuranceSplit
    {
        $request ??= ['sale' => $sale, 'split' => $split];

        if ($earlier = $this->createdUnder((string) $organization->id, $key, $request)) {
            return $earlier;
        }

        $jurisdiction = $this->jurisdictions->forRules($organization);
        $port = $jurisdiction->splitBilling();

        if (($reason = $port->unavailable()) !== null) {
            throw ValidationException::withMessages(['split' => $reason]);
        }

        $recipient = (string) ($split['recipient'] ?? InsuranceSplit::PATIENT);
        $lines = array_values((array) $sale['lines']);
        $payers = array_map(fn (array $line): ?string => $line['payer'] ?? null, $lines);
        $lines = array_map(fn (array $line): array => array_diff_key($line, ['payer' => true]), $lines);
        $insurer = $this->insurer((string) $organization->id, $split);
        $inclusive = array_key_exists('prices_include_vat', $sale) && $sale['prices_include_vat'] !== null
            ? (bool) $sale['prices_include_vat'] : (bool) $organization->prices_include_vat;
        $header = [...array_diff_key($sale, array_flip(self::SPLIT_ONLY)), 'prices_include_vat' => $inclusive, 'document_type' => DocumentType::Invoice->value];

        if ($recipient === InsuranceSplit::INSURER) {
            $this->refusePatientShare($split, $payers);

            $invoice = CountryDraft::check($jurisdiction->invoiceRules(), [
                ...array_diff_key($header, array_flip(self::BUYER)),
                ...$insurer['buyer'],
                'type' => $port->insurerType(),
                'order_reference' => $split['claim_reference'] ?? null,
                'contract_reference' => $split['policy_number'] ?? null,
                'buyer_reference' => $split['member_id'] ?? null,
                'lines' => array_map(fn (array $line): array => $port->insurerLine($line), $lines),
            ], 'insurer');
        } else {
            $invoice = CountryDraft::check($jurisdiction->invoiceRules(), [...$header, 'type' => $port->patientType(), 'lines' => $lines], 'patient');
        }

        return DB::transaction(function () use ($organization, $branchId, $tillId, $key, $request, $recipient, $split, $insurer, $sale, $invoice, $payers, $jurisdiction): InsuranceSplit {
            $method = $recipient === InsuranceSplit::INSURER ? InsuranceSplit::NO_SHARE : (string) $split['method'];
            $record = InsuranceSplit::acrossTenants()->create([
                'org_id' => $organization->id,
                'branch_id' => $branchId,
                'recipient' => $recipient,
                'insurer_customer_id' => $insurer['customer_id'],
                'insurer_name' => $insurer['name'],
                'insurer_vat_number' => $insurer['buyer']['buyer_vat_number'] ?? null,
                'patient_name' => $sale['buyer_name'] ?? null,
                'patient_name_ar' => $sale['buyer_name_ar'] ?? null,
                'patient_id' => $sale['buyer_id'] ?? null,
                'patient_id_scheme' => $sale['buyer_id_scheme'] ?? null,
                'method' => $method,
                'patient_percent' => $method === 'percent' ? $split['percent'] : null,
                'patient_amount' => $method === 'amount' ? $split['amount'] : null,
                // The sale's own treatment of each line, which the insurer's
                // invoice may not carry (SplitBilling::insurerLine()).
                'line_treatments' => array_map(fn (array $line): array => array_intersect_key($line, array_flip(self::TREATMENT)), array_values((array) $sale['lines'])),
                'currency' => (string) ($sale['currency'] ?? $jurisdiction->currency()),
                'sale_subtotal' => '0',
                'sale_tax' => '0',
                'sale_total' => '0',
                'policy_number' => $split['policy_number'] ?? null,
                'member_id' => $split['member_id'] ?? null,
                'claim_reference' => $split['claim_reference'] ?? null,
                'status' => InsuranceSplit::BILLED,
                'idempotency_key' => $key,
                'idempotency_hash' => $key === null ? null : IdempotentDraft::fingerprint($request),
            ]);

            $drafted = $this->drafter->draft(
                [...$invoice['draft'], 'insurance_split_id' => $record->id, 'split_role' => $recipient],
                (string) $organization->id, $branchId, $invoice['countryFields'], $tillId,
            );

            // The amounts for the account of each, with VAT, of the invoice
            // as drafted: they come to its total exactly.
            $total = (string) $drafted->total;
            $patientShare = $recipient === InsuranceSplit::INSURER ? '0.00' : ShareAllocation::patientShare(
                $method,
                $method === 'percent' ? (string) $split['percent'] : ($method === 'amount' ? (string) $split['amount'] : null),
                $total,
                $drafted->lines->map(fn (InvoiceLine $line): string => (string) $line->line_total)->all(),
                $payers,
            );

            $record->forceFill([
                'sale_subtotal' => $drafted->subtotal,
                'sale_tax' => $drafted->tax_amount,
                'sale_total' => $total,
                'patient_share' => $patientShare,
                'insurer_share' => bcsub($total, $patientShare, 2),
            ])->save();

            return $record->load('invoices.lines');
        });
    }

    /**
     * Issue the sale's invoice, or carry on filing it when it is issued.
     *
     * @return array{invoice: IssueOutcome|null}
     */
    public function issue(InsuranceSplit $split, Organization $organization, IssueOptions $options): array
    {
        $invoice = $split->load('invoices')->sale();

        if ($invoice === null) {
            return ['invoice' => null];
        }

        return ['invoice' => $invoice->status === InvoiceStatus::Draft
            ? $this->engine->issue($invoice, $organization, $options)
            : ($this->engine->resume($invoice, $organization, $options) ?? IssueOutcome::issuedOnly())];
    }

    /**
     * The insurer's decision on what it was billed (§5.3.2, p25).
     *
     * The patient is the recipient: nothing is corrected. The supply and its
     * VAT stand as invoiced, and what the insurer does not pay the patient
     * owes - "The Private Healthcare Provider should seek repayment from the
     * individual patient of the value of the service, in addition to VAT".
     * $approved is what the insurer pays of its share, with VAT.
     *
     * The insurer is the recipient: $approved is the value it accepts, before
     * VAT, which follows the value ("VAT should be calculated based on the
     * net discounted price", §5.3.1; Example 12's credit note states the value
     * and its VAT, p26). The rest is credited on its invoice - "a credit note
     * should be issued to record the adjustment to the price. The credit note
     * must set out the serial number of the original invoice" (p25,
     * Implementing Regulations Art. 54) - for one of two reasons:
     *
     *   price        its agreed price (§5.3.1, Example 12): the credit note
     *                alone.
     *   not_covered  the care is not covered, so "the supply of services will
     *                have been provided directly to the individual patient
     *                and VAT is chargeable" (p25): the credit note, and an
     *                invoice to the patient for it, taxed as the supply to
     *                the patient is - a citizen's private healthcare at zero
     *                with their national ID, the State bearing the VAT
     *                (§5.3.3, p26; BR-KSA-49).
     *
     * The value declined is spread over the insurer's lines in proportion to
     * their net values, a line apiece (ShareAllocation::apportion()), so the
     * credit note and the patient's invoice state the same items at the same
     * values. Recorded once.
     *
     * @return array{credit: IssueOutcome|null, rebill: IssueOutcome|null}
     *
     * @throws ValidationException when it cannot be settled so
     */
    public function settle(InsuranceSplit $split, Organization $organization, string $approved, ?string $decision, ?string $reason, IssueOptions $options): array
    {
        $split->load('invoices.lines', 'invoices.charges');
        $invoice = $split->sale();

        if ($split->status !== InsuranceSplit::BILLED) {
            throw ValidationException::withMessages(['approved' => __('The insurer\'s decision on this sale is already recorded.')]);
        }

        if ($invoice === null || ! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Accepted], true)) {
            throw ValidationException::withMessages(['approved' => __('Issue the invoice before recording the insurer\'s decision.')]);
        }

        $ceiling = $split->recipient === InsuranceSplit::PATIENT ? (string) $split->insurer_share : (string) $invoice->subtotal;

        if (! is_numeric($approved) || bccomp($approved, '0', 2) < 0 || bccomp($approved, $ceiling, 2) > 0) {
            throw ValidationException::withMessages(['approved' => $split->recipient === InsuranceSplit::PATIENT
                ? __('The insurer pays between 0 and its share, :total.', ['total' => $ceiling])
                : __('The value the insurer accepts, before VAT, is between 0 and the invoice\'s, :total.', ['total' => $ceiling])]);
        }

        $declined = bcsub($ceiling, $approved, 2);

        if ($split->recipient === InsuranceSplit::PATIENT || bccomp($declined, '0', 2) === 0) {
            DB::transaction(function () use ($split, $approved, $declined): void {
                $this->claim($split);
                $this->settled($split, $approved, bccomp($declined, '0', 2) === 0 ? null : InsuranceSplit::NOT_COVERED, null, null);
            });

            return ['credit' => null, 'rebill' => null];
        }

        if (! in_array($decision, [InsuranceSplit::NOT_COVERED, InsuranceSplit::PRICE], true)) {
            throw ValidationException::withMessages(['decision' => __('Say why the insurer accepts less: the care is not covered, or its agreed price applies.')]);
        }

        $jurisdiction = $this->jurisdictions->forRules($organization);
        $values = ShareAllocation::apportion($declined, $invoice->lines->map(fn (InvoiceLine $line): string => (string) $line->net_amount)->all());
        $official = $decision === InsuranceSplit::PRICE ? self::PRICE_REASON : self::NOT_COVERED_REASON;

        $credit = CountryDraft::check($jurisdiction->invoiceRules(), [
            ...$this->adjustments->fields($invoice, DocumentType::CreditNote),
            'issue_date' => $this->today($jurisdiction),
            'adjustment_reason' => filled($reason) ? (string) $reason : (string) AdjustmentReason::bilingual($official),
            'lines' => $this->valueLines($invoice, $values, fn (int $index, InvoiceLine $line): array => [
                'tax_category' => (string) $line->tax_category, 'tax_rate' => (string) (float) $line->tax_rate,
                'exempt_code' => $line->exempt_code, 'exempt_reason' => $line->exempt_reason,
            ]),
            'discount_amount' => null,
            'prices_include_vat' => false,
        ], 'credit');

        $rebill = $decision !== InsuranceSplit::NOT_COVERED ? null : CountryDraft::check($jurisdiction->invoiceRules(), [
            'type' => $jurisdiction->splitBilling()->patientType(),
            'document_type' => DocumentType::Invoice->value,
            'issue_date' => $this->today($jurisdiction),
            'supply_date' => $invoice->supply_date?->toDateString(),
            'currency' => (string) $invoice->currency,
            'exchange_rate' => $invoice->exchange_rate === null ? null : (string) (float) $invoice->exchange_rate,
            'payment_means_code' => $invoice->payment_means_code,
            'buyer_name' => $split->patient_name,
            'buyer_name_ar' => $split->patient_name_ar,
            'buyer_id' => $split->patient_id,
            'buyer_id_scheme' => $split->patient_id_scheme,
            // The supply to the patient, taxed as the sale gave it.
            'lines' => $this->valueLines($invoice, $values, fn (int $index, InvoiceLine $line): array => (array) ($split->line_treatments[$index] ?? [])),
            'prices_include_vat' => false,
        ], 'rebill');

        [$note, $patientInvoice] = DB::transaction(function () use ($split, $credit, $rebill, $approved, $decision): array {
            $this->claim($split);
            $note = $this->drafter->draft($credit['draft'], (string) $split->org_id, $split->branch_id, $credit['countryFields']);
            $patientInvoice = $rebill === null ? null : $this->drafter->draft(
                [...$rebill['draft'], 'insurance_split_id' => $split->id, 'split_role' => InsuranceSplit::REBILL],
                (string) $split->org_id, $split->branch_id, $rebill['countryFields'],
            );

            $this->settled($split, $approved, $decision, $note, $patientInvoice);

            return [$note, $patientInvoice];
        });

        $creditOutcome = $this->engine->issue($note, $organization, $options);
        $rebillOutcome = $patientInvoice !== null && $creditOutcome->issued ? $this->engine->issue($patientInvoice, $organization, $options) : null;

        return ['credit' => $creditOutcome, 'rebill' => $rebillOutcome];
    }

    /**
     * The invoice's lines at the values given them, one apiece, each with
     * the treatment $treatment gives it; a line given nothing is left out.
     *
     * @param  list<string>  $values
     * @param  callable(int, InvoiceLine): array<string, mixed>  $treatment
     * @return list<array<string, mixed>>
     */
    private function valueLines(Invoice $invoice, array $values, callable $treatment): array
    {
        $lines = [];

        foreach ($invoice->lines->values() as $index => $line) {
            if (bccomp($values[$index] ?? '0', '0', 2) <= 0) {
                continue;
            }

            $lines[] = array_filter([
                'description' => (string) $line->description,
                'description_ar' => (string) $line->description_ar,
                'quantity' => '1',
                'unit_price' => $values[$index],
                ...$treatment($index, $line),
            ], fn ($value): bool => $value !== null && $value !== '');
        }

        return $lines;
    }

    /**
     * The split re-read under a lock, inside the caller's transaction, and
     * still waiting for the insurer: two clicks that both read it billed
     * cannot both draft a credit note - the second waits here, then finds
     * it settled.
     *
     * @throws ValidationException when it was settled meanwhile
     */
    private function claim(InsuranceSplit $split): void
    {
        $locked = InsuranceSplit::acrossTenants()->lockForUpdate()->findOrFail($split->id);

        if ($locked->status !== InsuranceSplit::BILLED) {
            throw ValidationException::withMessages(['approved' => __('The insurer\'s decision on this sale is already recorded.')]);
        }
    }

    private function settled(InsuranceSplit $split, string $approved, ?string $decision, ?Invoice $note, ?Invoice $invoice): void
    {
        $split->forceFill([
            'status' => InsuranceSplit::SETTLED,
            'insurer_approved' => $approved,
            'decision' => $decision,
            'credit_note_id' => $note?->id,
            'rebill_invoice_id' => $invoice?->id,
            'settled_at' => now(),
            'settled_by' => auth()->id(),
        ])->save();
    }

    /**
     * A patient who pays a share of the bill is obliged to pay the provider,
     * so is a party to the supply and its recipient (§5.1, Example 11 p23).
     *
     * @param  array<string, mixed>  $split
     * @param  list<string|null>  $payers
     */
    private function refusePatientShare(array $split, array $payers): void
    {
        $share = (is_numeric($split['percent'] ?? null) && bccomp((string) $split['percent'], '0', 2) > 0)
            || (is_numeric($split['amount'] ?? null) && bccomp((string) $split['amount'], '0', 2) > 0)
            || in_array(ShareAllocation::PATIENT, $payers, true);

        if ($share) {
            throw ValidationException::withMessages(['split.recipient' => __('A patient who pays part of the bill is the recipient of the care, so the invoice is theirs, showing the insurer\'s share for its account. Bill the insurer as the recipient only when it contracted for the care and the patient pays nothing.')]);
        }
    }

    /**
     * The insurer the request names: a saved insurer, or one given in full.
     *
     * @param  array<string, mixed>  $split
     * @return array{customer_id: string|null, name: string, buyer: array<string, mixed>}
     */
    private function insurer(string $organizationId, array $split): array
    {
        $id = $split['insurer_customer_id'] ?? null;

        if (is_string($id)) {
            $customer = $this->catalogue->customer($organizationId, $id);

            if (! $customer instanceof Customer || ! $customer->is_insurer) {
                throw ValidationException::withMessages(['split.insurer_customer_id' => __('Choose one of your customers marked as an insurer.')]);
            }

            return ['customer_id' => (string) $customer->id, 'name' => (string) $customer->name, 'buyer' => [
                'customer_id' => (string) $customer->id,
                'buyer_name' => $customer->name,
                'buyer_name_ar' => $customer->name_ar,
                'buyer_vat_number' => $customer->vat_number,
                'buyer_address' => array_filter(array_combine(Customer::ADDRESS, array_map(fn (string $key) => $customer->{$key}, Customer::ADDRESS)), 'filled') ?: null,
                ...array_filter(['buyer_id' => $customer->buyer_id, 'buyer_id_scheme' => $customer->buyer_id_scheme, 'payment_terms' => $customer->payment_terms], 'filled'),
            ]];
        }

        $given = (array) ($split['insurer'] ?? []);

        return ['customer_id' => null, 'name' => (string) ($given['name'] ?? ''), 'buyer' => array_filter([
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
}
