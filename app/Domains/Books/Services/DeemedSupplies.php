<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Enums\DeemedSupplyKind;
use App\Domains\Books\Models\DeemedSupply;
use App\Domains\Compliance\Engine\DTOs\DeemedSupplyRules;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Services\CountryDraft;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Deemed supplies: goods or services that leave the business without a sale
 * - taken for a non-business use, given away as gifts or samples or to
 * employees, kept on deregistration - on which input tax was deducted.
 *
 * Each is recorded with its value and the share of its input tax deducted,
 * and decided by the country's rules (SpecialSupplies::deemedSupplies):
 *
 *   not deemed  no input tax was deducted on it;
 *   relieved    a gift, sample or the like within the allowance per
 *               recipient, and within the country's yearly cap, or output
 *               tax within the country's cap on all deemed supplies;
 *   taxed       otherwise: on the value times the share of input tax
 *               deducted, or, past a tax cap, on the excess only.
 *
 * A taxed one drafts the invoice that states its output tax (SpecialSupplies
 * ::deemedSupply), kept by the seller and issued like any draft: it is what
 * puts the output tax in the return, in the standard-rated sales. No
 * customer is invoiced.
 */
class DeemedSupplies
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly InvoiceDrafter $drafter,
    ) {}

    /**
     * @param  string|null  $kind  the kind asked for: a gift, sample or one to an employee names its recipient
     * @return array<string, list<mixed>>
     */
    public function rules(?string $kind): array
    {
        $recipientKinds = [DeemedSupplyKind::Gift->value, DeemedSupplyKind::Sample->value, DeemedSupplyKind::Employee->value];

        return [
            'kind' => ['required', Rule::enum(DeemedSupplyKind::class)],
            'description' => ['required', 'string', 'max:150'],
            'description_ar' => ['nullable', 'string', 'max:150'],
            'recipient' => ['nullable', 'string', 'max:255', Rule::requiredIf(in_array($kind, $recipientKinds, true))],
            'supplied_on' => ['required', 'date'],
            'value' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'input_tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }

    /**
     * Record a deemed supply, as validated by rules(), decide it, and draft
     * its invoice when it is taxed.
     *
     * @param  array<string, mixed>  $validated
     */
    public function record(Organization $organization, array $validated, ?string $branchId = null): DeemedSupply
    {
        $jurisdiction = $this->jurisdictions->forRules($organization);
        $rules = $jurisdiction->specialSupplies()->deemedSupplies();
        $kind = DeemedSupplyKind::from((string) $validated['kind']);
        $on = CarbonImmutable::parse($validated['supplied_on'])->startOfDay();
        $value = number_format((float) $validated['value'], 2, '.', '');
        $percent = number_format((float) ($validated['input_tax_percent'] ?? 100), 2, '.', '');
        $recipient = filled($validated['recipient'] ?? null) ? trim((string) $validated['recipient']) : null;
        $rate = $jurisdiction->invoiceRules()->standardRate();

        return DB::transaction(function () use ($organization, $validated, $branchId, $rules, $kind, $on, $value, $percent, $recipient, $rate): DeemedSupply {
            // One at a time per organization: each decision reads the ones before it.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();
            $decision = $this->decide($organization, $rules, $kind, $on, $value, $percent, $recipient, $rate);
            $description = (string) $validated['description'];
            $arabic = filled($validated['description_ar'] ?? null) ? (string) $validated['description_ar'] : null;
            $invoiceId = $decision['outcome'] === DeemedSupply::TAXED
                ? $this->invoice($organization, $branchId, $on, $recipient, $description, $arabic, $decision['taxable_value'], $rate)
                : null;

            return DeemedSupply::withoutTenantScope(fn () => DeemedSupply::query()->create([
                'org_id' => $organization->id,
                'branch_id' => $branchId,
                'kind' => $kind,
                'description' => $description,
                'description_ar' => $arabic,
                'recipient' => $recipient,
                'supplied_on' => $on->toDateString(),
                'value' => $value,
                'input_tax_percent' => $percent,
                'invoice_id' => $invoiceId,
                ...$decision,
            ]));
        });
    }

    /**
     * Draft the kept invoice that states a deemed supply's output tax, and
     * give its id.
     */
    private function invoice(Organization $organization, ?string $branchId, CarbonImmutable $on, ?string $recipient, string $description, ?string $arabic, string $taxable, string $rate): string
    {
        $jurisdiction = $this->jurisdictions->forRules($organization);
        $checked = CountryDraft::check($jurisdiction->invoiceRules(), $jurisdiction->specialSupplies()->deemedSupply(array_filter([
            'document_type' => DocumentType::Invoice->value,
            'issue_date' => $on->toDateString(),
            'supply_date' => $on->toDateString(),
            'currency' => $jurisdiction->currency(),
            'prices_include_vat' => false,
            'buyer_name' => $recipient,
            'notes' => __('Deemed supply, kept by the seller: no customer is invoiced.', [], 'ar')."\n".__('Deemed supply, kept by the seller: no customer is invoiced.', [], 'en'),
            'lines' => [[
                'description' => mb_substr(__('Deemed supply: :what', ['what' => $description], 'en'), 0, 255),
                'description_ar' => mb_substr(__('Deemed supply: :what', ['what' => $arabic ?? $description], 'ar'), 0, 255),
                'item_name' => mb_substr($description, 0, 255),
                'quantity' => '1',
                'unit_price' => $taxable,
                'tax_category' => 'S',
                'tax_rate' => $rate,
            ]],
        ], fn (mixed $field): bool => $field !== null), $organization), 'deemed');

        return (string) $this->drafter->draft($checked['draft'], (string) $organization->id, $branchId, $checked['countryFields'])->id;
    }

    /** @return Collection<int, DeemedSupply> */
    public function list(string $organizationId): Collection
    {
        return DeemedSupply::withoutTenantScope(fn () => DeemedSupply::query()->where('org_id', $organizationId)->orderByDesc('supplied_on')->orderByDesc('created_at')->limit(500)->get());
    }

    public function rulesFor(Organization $organization): DeemedSupplyRules
    {
        return $this->jurisdictions->forRules($organization)->specialSupplies()->deemedSupplies();
    }

    /**
     * The outcome, its reason and amounts.
     *
     * @return array{outcome: string, reason: string, taxable_value: string, tax_before_cap: string, tax_amount: string}
     */
    private function decide(Organization $organization, DeemedSupplyRules $rules, DeemedSupplyKind $kind, CarbonImmutable $on, string $value, string $percent, ?string $recipient, string $rate): array
    {
        $none = ['taxable_value' => '0.00', 'tax_before_cap' => '0.00', 'tax_amount' => '0.00'];

        if (bccomp($percent, '0', 2) === 0) {
            return [...$none, 'outcome' => DeemedSupply::NOT_DEEMED, 'reason' => __('No input tax was deducted on it, so it is not a deemed supply (:source).', ['source' => $rules->source])];
        }

        [$from, $to] = $this->window($rules, $on);
        $earlier = DeemedSupply::withoutTenantScope(fn () => DeemedSupply::query()->where('org_id', $organization->id)
            ->whereBetween('supplied_on', [$from->toDateString(), $to->toDateString()])->get());

        if ($recipient !== null && in_array($kind->value, $rules->recipientKinds, true)) {
            $free = $earlier->filter(fn (DeemedSupply $row): bool => in_array($row->kind->value, $rules->recipientKinds, true));
            $theirs = $free->filter(fn (DeemedSupply $row): bool => mb_strtolower((string) $row->recipient) === mb_strtolower($recipient));
            $toThem = bcadd(self::sum($theirs, 'value'), $value, 2);
            $inAll = bcadd(self::sum($free, 'value'), $value, 2);

            if (bccomp($toThem, $rules->recipientAllowance, 2) <= 0 && ($rules->reliefCap === null || bccomp($inAll, $rules->reliefCap, 2) <= 0)) {
                return [...$none, 'outcome' => DeemedSupply::RELIEVED, 'reason' => __('Within :allowance to this recipient in the period (:source).', ['allowance' => $rules->recipientAllowance, 'source' => $rules->source])];
            }
        }

        $taxable = self::round(bcdiv(bcmul($value, $percent, 8), '100', 8));
        $tax = self::round(bcdiv(bcmul($taxable, $rate, 8), '100', 8));

        if ($rules->taxCap === null) {
            return ['outcome' => DeemedSupply::TAXED, 'reason' => __('Taxed as a deemed supply (:source).', ['source' => $rules->source]), 'taxable_value' => $taxable, 'tax_before_cap' => $tax, 'tax_amount' => $tax];
        }

        // Only output tax past the cap over the window is payable.
        $before = self::sum($earlier, 'tax_before_cap');
        $after = bcadd($before, $tax, 2);
        $payable = bcsub(self::over($after, $rules->taxCap), self::over($before, $rules->taxCap), 2);

        if (bccomp($payable, '0', 2) <= 0) {
            return [...$none, 'tax_before_cap' => $tax, 'outcome' => DeemedSupply::RELIEVED, 'reason' => __('Output tax on deemed supplies in the period is within :cap (:source).', ['cap' => $rules->taxCap, 'source' => $rules->source])];
        }

        $taxedValue = bccomp($payable, $tax, 2) === 0 ? $taxable : self::round(bcdiv(bcmul($payable, '100', 8), $rate, 8));

        return [
            'outcome' => DeemedSupply::TAXED,
            'reason' => bccomp($payable, $tax, 2) === 0
                ? __('Taxed as a deemed supply (:source).', ['source' => $rules->source])
                : __('Taxed on the output tax past :cap in the period (:source).', ['cap' => $rules->taxCap, 'source' => $rules->source]),
            'taxable_value' => $taxedValue,
            'tax_before_cap' => $tax,
            'tax_amount' => self::round(bcdiv(bcmul($taxedValue, $rate, 8), '100', 8)),
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function window(DeemedSupplyRules $rules, CarbonImmutable $on): array
    {
        return $rules->window === 'calendar_year'
            ? [$on->startOfYear(), $on->endOfYear()->startOfDay()]
            : [$on->endOfMonth()->subYearNoOverflow()->addDay()->startOfDay(), $on->endOfMonth()->startOfDay()];
    }

    /** @param  Collection<int, DeemedSupply>  $rows */
    private static function sum(Collection $rows, string $field): string
    {
        return $rows->reduce(fn (string $total, DeemedSupply $row): string => bcadd($total, (string) $row->{$field}, 2), '0.00');
    }

    private static function over(string $amount, string $cap): string
    {
        return bccomp($amount, $cap, 2) > 0 ? bcsub($amount, $cap, 2) : '0.00';
    }

    private static function round(string $value): string
    {
        return bcadd(bcadd($value, bccomp($value, '0', 8) < 0 ? '-0.005' : '0.005', 8), '0', 2);
    }
}
