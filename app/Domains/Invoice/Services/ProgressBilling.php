<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Models\Contract;
use App\Domains\Invoice\Models\ContractInvoice;
use App\Domains\Invoice\Models\ContractStage;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A contract billed as it progresses - construction, contracting - with a
 * share of each stage withheld as retention and released later.
 *
 * A stage is invoiced once, for its value less the retention withheld from
 * it: VAT is due on what the invoice states, when it is issued. The
 * retention is invoiced when it is released, as its own tax invoice, and
 * the statement says by when the tax on it falls due if it is still held
 * (SpecialSupplies::retentionDueBy). How each country reads a supply billed
 * in parts, and what its document adds, is the country's
 * (SpecialSupplies::progress): the UAE has the milestone invoice net of
 * retention and the calculation on a separate commercial document - this
 * contract's statement - not on the e-invoice (Electronic Invoicing
 * Guidelines v1.1, scenario 5; Appendix 5 §17.2).
 *
 * Not a schedule: a schedule bills by the calendar (Schedules,
 * Instalments), and a stage is billed when the work reaches it, for its
 * own agreed value. The documents are drafted through the country's rules
 * (CountryDraft) and issued like any draft.
 */
class ProgressBilling
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly InvoiceDrafter $drafter,
    ) {}

    /**
     * A contract: its customer, reference, value before VAT and retention,
     * the VAT treatment of its work, and its stages, each an amount or a
     * percentage of the value.
     *
     * @return array<string, list<mixed>>
     */
    public function contractRules(Organization $organization): array
    {
        $rules = $this->jurisdictions->forRules($organization)->invoiceRules();

        return [
            'customer_id' => ['required', 'string', 'uuid', Rule::exists('customers', 'id')->where('org_id', $organization->id)->whereNull('deleted_at')],
            'reference' => ['required', 'string', 'max:127', Rule::unique('contracts', 'reference')->where('org_id', $organization->id)],
            'title' => ['required', 'string', 'max:200'],
            'title_ar' => ['nullable', 'string', 'max:200'],
            'value' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'retention_percent' => ['nullable', 'numeric', 'min:0', 'max:50', 'decimal:0,2'],
            'tax_category' => ['nullable', 'string', Rule::in($rules->taxCategories())],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'exempt_code' => ['nullable', 'string', Rule::in($rules->exemptionCodes())],
            'exempt_reason' => ['nullable', 'string', 'max:255'],
            'stages' => ['required', 'array', 'min:1', 'max:100'],
            'stages.*.name' => ['required', 'string', 'max:150'],
            'stages.*.name_ar' => ['nullable', 'string', 'max:150'],
            'stages.*.percent' => ['nullable', 'required_without:stages.*.amount', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'stages.*.amount' => ['nullable', 'required_without:stages.*.percent', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * Record a contract, as validated by contractRules(). A stage given as
     * a percentage is that share of the value, rounded; when the stages are
     * all percentages that come to 100, the last takes what is left, so they
     * add up to the value exactly. Together they never come to more than it.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function create(Organization $organization, array $validated, ?string $branchId = null): Contract
    {
        $value = number_format((float) $validated['value'], 2, '.', '');
        $stages = array_values((array) $validated['stages']);
        $percents = array_map(fn (array $stage): ?string => isset($stage['percent']) ? (string) $stage['percent'] : null, $stages);
        $whole = ! in_array(null, $percents, true) && bccomp(array_reduce($percents, fn (string $sum, string $percent): string => bcadd($sum, $percent, 4), '0'), '100', 4) === 0;
        $amounts = [];

        foreach ($stages as $index => $stage) {
            $amounts[$index] = isset($stage['percent'])
                ? self::round(bcdiv(bcmul($value, (string) $stage['percent'], 8), '100', 8))
                : number_format((float) $stage['amount'], 2, '.', '');
        }

        if ($whole) {
            $last = count($amounts) - 1;
            $amounts[$last] = bcsub($value, array_reduce(array_slice($amounts, 0, $last), fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0'), 2);
        }

        if (bccomp(array_reduce($amounts, fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0'), $value, 2) > 0) {
            throw ValidationException::withMessages(['stages' => __('The stages come to more than the contract value, :value.', ['value' => $value])]);
        }

        $rules = $this->jurisdictions->forRules($organization)->invoiceRules();
        $category = (string) ($validated['tax_category'] ?? 'S');

        return DB::transaction(function () use ($organization, $validated, $branchId, $value, $stages, $amounts, $category, $rules): Contract {
            $contract = Contract::acrossTenants()->create([
                'org_id' => $organization->id,
                'branch_id' => $branchId,
                'customer_id' => $validated['customer_id'],
                'reference' => $validated['reference'],
                'title' => $validated['title'],
                'title_ar' => $validated['title_ar'] ?? null,
                'value' => $value,
                'retention_percent' => (string) ($validated['retention_percent'] ?? '0'),
                'tax_category' => $category,
                'tax_rate' => $category === 'S' ? (string) ($validated['tax_rate'] ?? $rules->standardRate()) : '0',
                'exempt_code' => $category === 'S' ? null : ($validated['exempt_code'] ?? null),
                'exempt_reason' => $category === 'S' ? null : ($validated['exempt_reason'] ?? null),
            ]);

            foreach ($stages as $index => $stage) {
                ContractStage::query()->create([
                    'contract_id' => $contract->id,
                    'position' => $index + 1,
                    'name' => $stage['name'],
                    'name_ar' => $stage['name_ar'] ?? null,
                    'percent' => $stage['percent'] ?? null,
                    'amount' => $amounts[$index],
                ]);
            }

            return $contract->load('stages');
        });
    }

    /** @return Collection<int, Contract> */
    public function list(string $organizationId): Collection
    {
        return Contract::acrossTenants()->where('org_id', $organizationId)
            ->with(['customer' => fn ($customer) => $customer->acrossTenants()->select(['id', 'name', 'name_ar'])])->latest()->limit(200)->get();
    }

    public function find(string $organizationId, string $id): ?Contract
    {
        return Contract::acrossTenants()->where('org_id', $organizationId)
            ->with([
                'stages',
                'customer' => fn ($customer) => $customer->acrossTenants(),
                'invoices.invoice' => fn ($invoice) => $invoice->acrossTenants()->select(['id', 'invoice_number', 'status', 'issue_date', 'total', 'tax_amount']),
            ])->find($id);
    }

    /**
     * Draft a stage's invoice: its value less the retention withheld from
     * it, at the contract's VAT treatment, naming the contract (BT-12).
     *
     * @throws ValidationException
     */
    public function invoiceStage(Organization $organization, Contract $contract, string $stageId, CarbonImmutable $issueDate, ?CarbonImmutable $dueDate = null): Invoice
    {
        $stage = $contract->stages->firstWhere('id', $stageId) ?? throw ValidationException::withMessages(['stage' => __('That is not a stage of this contract.')]);
        $retention = self::round(bcdiv(bcmul((string) $stage->amount, (string) $contract->retention_percent, 8), '100', 8));
        $net = bcsub((string) $stage->amount, $retention, 2);
        $said = ['position' => $stage->position, 'name' => $stage->name, 'reference' => $contract->reference];
        $saidAr = ['position' => $stage->position, 'name' => $stage->name_ar ?: $stage->name, 'reference' => $contract->reference];

        $jurisdiction = $this->jurisdictions->forRules($organization);
        $checked = CountryDraft::check($jurisdiction->invoiceRules(), $jurisdiction->specialSupplies()->progress($this->payload($jurisdiction, $contract, $issueDate, $dueDate, [
            'description' => mb_substr(__('Stage :position: :name, contract :reference', $said, 'en'), 0, 255),
            'description_ar' => mb_substr(__('Stage :position: :name, contract :reference', $saidAr, 'ar'), 0, 255),
            'unit_price' => $net,
        ])), 'stage');

        return DB::transaction(function () use ($organization, $contract, $stage, $checked, $retention): Invoice {
            $this->lock($contract);

            if (ContractInvoice::query()->where('stage_id', $stage->id)->exists()) {
                throw ValidationException::withMessages(['stage' => __('Stage :position is already invoiced.', ['position' => $stage->position])]);
            }

            $invoice = $this->drafter->draft($checked['draft'], (string) $organization->id, $contract->branch_id, $checked['countryFields']);
            ContractInvoice::query()->create([
                'contract_id' => $contract->id, 'invoice_id' => $invoice->id, 'stage_id' => $stage->id, 'kind' => ContractInvoice::STAGE,
                'stage_value' => (string) $stage->amount, 'retention' => $retention,
            ]);

            return $invoice;
        });
    }

    /**
     * Draft the invoice releasing retention: what is still held, or the part
     * given, as its own tax invoice when the customer becomes liable to pay
     * it (UAE Electronic Invoicing Guidelines v1.1, Appendix 5 §17.2).
     *
     * @throws ValidationException
     */
    public function release(Organization $organization, Contract $contract, ?string $amount, CarbonImmutable $issueDate, ?CarbonImmutable $dueDate = null): Invoice
    {
        $held = $this->statement($contract)['retention_held'];
        $amount = $amount === null ? $held : number_format((float) $amount, 2, '.', '');

        if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $held, 2) > 0) {
            throw ValidationException::withMessages(['amount' => __('Release between 0.01 and the retention held, :held.', ['held' => $held])]);
        }

        $said = ['reference' => $contract->reference];
        $jurisdiction = $this->jurisdictions->forRules($organization);
        $checked = CountryDraft::check($jurisdiction->invoiceRules(), $jurisdiction->specialSupplies()->progress($this->payload($jurisdiction, $contract, $issueDate, $dueDate, [
            'description' => __('Retention released, contract :reference', $said, 'en'),
            'description_ar' => __('Retention released, contract :reference', $said, 'ar'),
            'unit_price' => $amount,
        ])), 'release');

        return DB::transaction(function () use ($organization, $contract, $checked, $amount): Invoice {
            $this->lock($contract);

            if (bccomp($amount, $this->statement($contract->fresh(['stages', 'invoices.invoice']))['retention_held'], 2) > 0) {
                throw ValidationException::withMessages(['amount' => __('The retention held changed meanwhile. Try again.')]);
            }

            $invoice = $this->drafter->draft($checked['draft'], (string) $organization->id, $contract->branch_id, $checked['countryFields']);
            ContractInvoice::query()->create([
                'contract_id' => $contract->id, 'invoice_id' => $invoice->id, 'kind' => ContractInvoice::RELEASE, 'released' => $amount,
            ]);

            return $invoice;
        });
    }

    /**
     * Where the contract stands, before VAT: its value, what its stages
     * billed (their value), what was withheld and released, what is still
     * held and by when the tax on it falls due, and what is left to bill.
     * Documents the authority refused do not count; drafts do, as they hold
     * their stage.
     *
     * @return array{value: string, billed: string, retained: string, released: string, retention_held: string, remaining: string, invoiced: string, retention_due_by: ?string, stages: list<array<string, mixed>>, releases: list<array<string, mixed>>}
     */
    public function statement(Contract $contract): array
    {
        $contract->loadMissing(['stages', 'invoices.invoice']);
        $links = $contract->invoices->filter(fn (ContractInvoice $link): bool => $link->invoice !== null && $link->invoice->status !== InvoiceStatus::Rejected);
        $sum = fn (Collection $rows, string $field): string => $rows->reduce(fn (string $total, ContractInvoice $link): string => bcadd($total, (string) $link->{$field}, 2), '0.00');

        $stageLinks = $links->where('kind', ContractInvoice::STAGE);
        $billed = $sum($stageLinks, 'stage_value');
        $retained = $sum($stageLinks, 'retention');
        $released = $sum($links->where('kind', ContractInvoice::RELEASE), 'released');
        $held = bcsub($retained, $released, 2);
        $first = $stageLinks->filter(fn (ContractInvoice $link): bool => bccomp((string) $link->retention, '0', 2) > 0)
            ->map(fn (ContractInvoice $link): CarbonImmutable => CarbonImmutable::parse($link->invoice->issue_date))->sort()->first();
        $organization = Organization::query()->find($contract->org_id);

        return [
            'value' => (string) $contract->value,
            'billed' => $billed,
            'retained' => $retained,
            'released' => $released,
            'retention_held' => $held,
            'remaining' => bcsub((string) $contract->value, $billed, 2),
            'invoiced' => bcadd(bcsub($billed, $retained, 2), $released, 2),
            'retention_due_by' => bccomp($held, '0', 2) > 0 && $first !== null
                ? $this->jurisdictions->forRules($organization)->specialSupplies()->retentionDueBy($first)->toDateString() : null,
            'stages' => $contract->stages->map(function (ContractStage $stage) use ($stageLinks): array {
                $link = $stageLinks->firstWhere('stage_id', $stage->id);

                return [
                    'id' => $stage->id, 'position' => $stage->position, 'name' => $stage->name, 'name_ar' => $stage->name_ar,
                    'percent' => $stage->percent, 'amount' => (string) $stage->amount,
                    'retention' => $link === null ? null : (string) $link->retention,
                    'invoice' => $link?->invoice?->only(['id', 'invoice_number', 'status', 'issue_date', 'total']),
                ];
            })->values()->all(),
            'releases' => $links->where('kind', ContractInvoice::RELEASE)->map(fn (ContractInvoice $link): array => [
                'amount' => (string) $link->released, 'invoice' => $link->invoice->only(['id', 'invoice_number', 'status', 'issue_date', 'total']),
            ])->values()->all(),
        ];
    }

    /**
     * One invoice of the contract, in the fields every country's rules take.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function payload(Jurisdiction $jurisdiction, Contract $contract, CarbonImmutable $issueDate, ?CarbonImmutable $dueDate, array $line): array
    {
        $customer = Customer::acrossTenants()->withTrashed()->find($contract->customer_id)
            ?? throw ValidationException::withMessages(['customer_id' => __('Choose one of your customers.')]);

        return [
            ...$jurisdiction->recurringSupply()->buyerFrom($customer),
            'type' => InvoiceType::Standard->value,
            'document_type' => DocumentType::Invoice->value,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => ($dueDate ?? $issueDate)->toDateString(),
            'supply_date' => $issueDate->toDateString(),
            'currency' => $jurisdiction->currency(),
            'contract_reference' => mb_substr((string) $contract->reference, 0, 127),
            'prices_include_vat' => false,
            'lines' => [array_filter([
                ...$line,
                'item_name' => mb_substr((string) $line['description'], 0, 255),
                'quantity' => '1',
                'tax_category' => (string) $contract->tax_category,
                'tax_rate' => (string) (float) $contract->tax_rate,
                'exempt_code' => $contract->exempt_code,
                'exempt_reason' => $contract->exempt_reason,
            ], fn (mixed $value): bool => $value !== null && $value !== '')],
        ];
    }

    /** The contract re-read under a lock, so two clicks cannot bill one stage twice. */
    private function lock(Contract $contract): void
    {
        Contract::acrossTenants()->whereKey($contract->id)->lockForUpdate()->first();
    }

    private static function round(string $value): string
    {
        return bcadd(bcadd($value, bccomp($value, '0', 8) < 0 ? '-0.005' : '0.005', 8), '0', 2);
    }
}
