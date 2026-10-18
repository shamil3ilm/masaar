<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\VatSummary;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Services\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A day's close (a Z-report): what each device, and each branch, issued on
 * one day in the Kingdom.
 *
 * Per device because that is how ZATCA counts: each EGS unit keeps its own
 * counter and chain, so a till's close says which counters it used that day
 * - the first and the last, and whether they ran without a gap. Per branch
 * as well, because a branch without a certificate of its own issues on head
 * office's device, and a shop manager closes the shop, not the device.
 *
 * Every document issued that day is counted, refused ones too: each took a
 * counter. The amounts, in riyals, leave out what ZATCA refused, as the VAT
 * summary does, and a document in another currency without a rate, which is
 * named instead of guessed at. Credit notes are shown apart and taken off.
 */
class DailyClose
{
    public function __construct(
        private readonly VatSummary $vat,
        private readonly BranchScope $branches,
    ) {}

    /**
     * @return array{day: string, devices: list<array<string, mixed>>, branches: list<array<string, mixed>>}
     */
    public function for(string $organizationId, CarbonImmutable $day): array
    {
        $devices = [];
        $branches = [];

        foreach ($this->documents($organizationId, $day) as $invoice) {
            $device = (string) ($invoice->device_id ?? $invoice->org_id);
            $branch = (string) ($invoice->branch_id ?? $invoice->org_id);

            $devices[$device] = $this->add($devices[$device] ?? $this->empty($device), $invoice);
            $branches[$branch] = $this->add($branches[$branch] ?? $this->empty($branch), $invoice);
        }

        $names = $this->names($organizationId, [...array_keys($devices), ...array_keys($branches)]);

        return [
            'day' => $day->toDateString(),
            'devices' => $this->finish($devices, $names, $organizationId),
            'branches' => $this->finish($branches, $names, $organizationId),
        ];
    }

    /**
     * Documents of the day's sales, on the Kingdom's clock: from its midnight
     * to the next, as instants. A till's sale counts on the day it was made
     * (sold_at), however late it was issued; anything else on the day it was
     * issued - issued_at, or created_at for a document issued before
     * issued_at was recorded.
     *
     * @return iterable<Invoice>
     */
    private function documents(string $organizationId, CarbonImmutable $day): iterable
    {
        $start = CarbonImmutable::instance(FatooraTime::saudiNow()->setDateFrom($day)->startOfDay())->utc();
        $end = $start->addDay();

        // Of the branches the close shows: the one chosen in the switcher,
        // or all those the person may see.
        return Invoice::withoutTenantScope(fn () => $this->branches->applyVisible(Invoice::query())
            ->where('org_id', $organizationId)
            ->whereNotNull('hash')
            ->where('status', '!=', InvoiceStatus::Draft)
            ->where('document_type', '!=', DocumentType::Proforma)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $sold) => $sold->where('sold_at', '>=', $start)->where('sold_at', '<', $end))
                ->orWhere(fn (Builder $issued) => $issued->whereNull('sold_at')->where('issued_at', '>=', $start)->where('issued_at', '<', $end))
                ->orWhere(fn (Builder $older) => $older->whereNull('sold_at')->whereNull('issued_at')->where('created_at', '>=', $start)->where('created_at', '<', $end)))
            ->with('lines')
            ->orderBy('icv')
            ->lazy(200));
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(string $id): array
    {
        return [
            'id' => $id,
            'documents' => 0,
            'invoices' => 0,
            'credit_notes' => 0,
            'debit_notes' => 0,
            'rejected' => 0,
            'sales' => ['net' => 0.0, 'vat' => 0.0, 'total' => 0.0],
            'returns' => ['net' => 0.0, 'vat' => 0.0, 'total' => 0.0],
            'rates' => [],
            'first' => null,
            'last' => null,
            'counters' => [],
            'missing_rate' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function add(array $group, Invoice $invoice): array
    {
        $group['documents']++;
        $group[match ($invoice->document_type) {
            DocumentType::CreditNote => 'credit_notes',
            DocumentType::DebitNote => 'debit_notes',
            default => 'invoices',
        }]++;
        $group['counters'][] = (int) $invoice->icv;

        // The lowest and the highest counter, which are the first and last
        // the device issued: a till's sale time can put a later counter on
        // an earlier sale.
        $mark = ['number' => (string) $invoice->invoice_number, 'counter' => (int) $invoice->icv, 'at' => $invoice->issuedMoment()];
        $group['first'] = $group['first'] === null || $mark['counter'] < $group['first']['counter'] ? $mark : $group['first'];
        $group['last'] = $group['last'] === null || $mark['counter'] > $group['last']['counter'] ? $mark : $group['last'];

        if ($invoice->status === InvoiceStatus::Rejected) {
            $group['rejected']++;

            return $group;
        }

        $rate = $this->vat->rate($invoice);

        if ($rate === null) {
            $group['missing_rate'][] = (string) $invoice->invoice_number;

            return $group;
        }

        $credit = $invoice->document_type === DocumentType::CreditNote;
        $side = $credit ? 'returns' : 'sales';

        foreach ($this->vat->categories($invoice) as $category) {
            $key = $this->rateKey($invoice, $category);
            $sign = $credit ? -1 : 1;

            $group['rates'][$key]['net'] = ($group['rates'][$key]['net'] ?? 0.0) + $sign * $category['base'] * $rate;
            $group['rates'][$key]['vat'] = ($group['rates'][$key]['vat'] ?? 0.0) + $sign * $category['tax'] * $rate;
            $group[$side]['net'] += $category['base'] * $rate;
            $group[$side]['vat'] += $category['tax'] * $rate;
        }

        $group[$side]['total'] = $group[$side]['net'] + $group[$side]['vat'];

        return $group;
    }

    /**
     * The VAT rate a category was charged at, as a key: "15" for standard,
     * and the category beside a zero rate, since zero-rated, exempt and out
     * of scope are three different things on a till's close.
     *
     * @param  array{code: string, base: float, tax: float}  $category
     */
    private function rateKey(Invoice $invoice, array $category): string
    {
        $rates = $invoice->lines
            ->filter(fn ($line): bool => ($line->tax_category instanceof \BackedEnum ? $line->tax_category->value : (string) ($line->tax_category ?: 'S')) === $category['code'])
            ->map(fn ($line): float => (float) $line->tax_rate)
            ->unique();

        $rate = rtrim(rtrim(number_format((float) $rates->first(), 2, '.', ''), '0'), '.');

        return $rate === '0' ? '0 ('.$category['code'].')' : $rate;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function finish(array $groups, array $names, string $organizationId): array
    {
        $rows = [];

        foreach ($groups as $id => $group) {
            $counters = $group['counters'];
            sort($counters);

            ksort($group['rates'], SORT_NATURAL);

            $rows[] = [
                ...$group,
                'name' => $id === $organizationId ? __('Head office') : ($names[$id] ?? $id),
                'head_office' => $id === $organizationId,
                // A device's counters that day ran without a gap: as many
                // documents as the span from the first to the last.
                'contiguous' => $counters === [] || end($counters) - $counters[0] + 1 === count($counters),
                'sales' => array_map(fn (float $v): float => round($v, 2), $group['sales']),
                'returns' => array_map(fn (float $v): float => round($v, 2), $group['returns']),
                'net' => round($group['sales']['total'] - $group['returns']['total'], 2),
                // A list rather than keyed by rate: PHP would make "15" an
                // integer key and "15.5" a string one.
                'rates' => array_map(
                    fn (string|int $rate, array $r): array => ['rate' => (string) $rate, 'net' => round($r['net'], 2), 'vat' => round($r['vat'], 2)],
                    array_keys($group['rates']),
                    $group['rates'],
                ),
            ];
        }

        // Head office first, then by name.
        usort($rows, fn (array $a, array $b): int => [! $a['head_office'], $a['name']] <=> [! $b['head_office'], $b['name']]);

        return array_map(function (array $row): array {
            unset($row['counters']);

            return $row;
        }, $rows);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function names(string $organizationId, array $ids): array
    {
        return Branch::withoutTenantScope(fn () => Branch::query()
            ->where('org_id', $organizationId)
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('name', 'id')
            ->all());
    }
}
