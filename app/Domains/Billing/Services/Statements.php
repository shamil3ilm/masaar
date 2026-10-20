<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A customer's account with the platform: its statement and its ageing.
 *
 * The statement is the account as a ledger in one currency - invoices
 * owed, money received, credits granted - with the balance after each line,
 * from an opening balance to a closing one. Money received is counted as
 * received, whatever it was later set against, so an overpayment shows as a
 * balance in the customer's favour; credits that only carry money already
 * counted (an overpayment's, a credit note's remainder) are not counted
 * again.
 *
 * The ageing is what is still owed by how long past due: not yet due, 1 to
 * 30 days, 31 to 60, 61 to 90, and over 90.
 */
class Statements
{
    /** The ageing's columns, in order. */
    public const BUCKETS = ['current', 'days_30', 'days_60', 'days_90', 'over_90'];

    /** Credits that are new money to the customer's name, not money counted already. */
    private const STATEMENT_CREDITS = [BillingCredit::ADJUSTMENT];

    public function __construct(
        private readonly Receivables $receivables,
    ) {}

    /** @return array<string, string> bucket => heading */
    public static function headings(): array
    {
        return [
            'current' => __('Not yet due'),
            'days_30' => __('1 to 30 days'),
            'days_60' => __('31 to 60 days'),
            'days_90' => __('61 to 90 days'),
            'over_90' => __('Over 90 days'),
        ];
    }

    /**
     * The currencies a customer has been invoiced or has paid in, the
     * customer's own currency first.
     *
     * @return list<string>
     */
    public function currencies(Organization $customer, string $own): array
    {
        $used = Receivable::withoutTenantScope(fn () => Receivable::query()->where('org_id', $customer->id)->distinct()->pluck('currency'))
            ->merge(Payment::withoutTenantScope(fn () => Payment::query()->where('org_id', $customer->id)->distinct()->pluck('currency')))
            ->map(fn ($currency): string => (string) $currency);

        return $used->prepend($own)->unique()->values()->all();
    }

    /**
     * @return array{currency: string, from: string, to: string, opening: string, closing: string, lines: list<array{date: string, kind: string, reference: string, description: string, debit: string, credit: string, balance: string}>}
     */
    public function statement(Organization $customer, string $currency, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $entries = $this->entries($customer, $currency);
        $start = $from->toDateString();
        $end = $to->toDateString();

        $opening = $entries->filter(fn (array $entry): bool => $entry['date'] < $start)->sum(fn (array $entry): float => $entry['debit'] - $entry['credit']);
        $balance = $opening;
        $lines = [];

        foreach ($entries->filter(fn (array $entry): bool => $entry['date'] >= $start && $entry['date'] <= $end) as $entry) {
            $balance += $entry['debit'] - $entry['credit'];
            $lines[] = [
                'date' => $entry['date'],
                'kind' => $entry['kind'],
                'reference' => $entry['reference'],
                'description' => $entry['description'],
                'debit' => $entry['debit'] > 0 ? Receivable::money($entry['debit']) : '',
                'credit' => $entry['credit'] > 0 ? Receivable::money($entry['credit']) : '',
                'balance' => Receivable::money($balance),
            ];
        }

        return [
            'currency' => $currency,
            'from' => $start,
            'to' => $end,
            'opening' => Receivable::money($opening),
            'closing' => Receivable::money($balance),
            'lines' => $lines,
        ];
    }

    /**
     * What is owed, by how long past due on a day: per currency, a row per
     * customer and the totals.
     *
     * @return array<string, array{rows: array<string, array<string, mixed>>, totals: array<string, string>}>
     */
    public function ageing(CarbonImmutable $on, ?string $organizationId = null): array
    {
        $outstanding = Receivable::withoutTenantScope(fn () => $this->receivables->query()
            ->whereIn('status', ReceivableStatus::outstandingValues())
            ->when($organizationId !== null, fn ($query) => $query->where('org_id', $organizationId))
            ->get());

        $ageing = [];

        foreach ($outstanding as $receivable) {
            $currency = (string) $receivable->currency;
            $customer = (string) $receivable->org_id;
            $ageing[$currency]['rows'][$customer] ??= [
                'organization_id' => $customer,
                'name' => (string) $receivable->organization?->name,
                ...array_fill_keys([...self::BUCKETS, 'total'], 0.0),
            ];
            $ageing[$currency]['totals'] ??= array_fill_keys([...self::BUCKETS, 'total'], 0.0);

            $bucket = $this->bucket($receivable->daysOverdue($on));
            $owed = (float) $receivable->balance();

            $ageing[$currency]['rows'][$customer][$bucket] += $owed;
            $ageing[$currency]['rows'][$customer]['total'] += $owed;
            $ageing[$currency]['totals'][$bucket] += $owed;
            $ageing[$currency]['totals']['total'] += $owed;
        }

        foreach ($ageing as $currency => $table) {
            uasort($table['rows'], fn (array $a, array $b): int => $b['total'] <=> $a['total']);

            $ageing[$currency] = [
                'rows' => array_map(fn (array $row): array => [...$row, ...array_map(fn ($value): string => Receivable::money((float) $value), array_intersect_key($row, array_flip([...self::BUCKETS, 'total'])))], $table['rows']),
                'totals' => array_map(fn ($value): string => Receivable::money((float) $value), $table['totals']),
            ];
        }

        ksort($ageing);

        return $ageing;
    }

    public function bucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'days_30',
            $daysOverdue <= 60 => 'days_60',
            $daysOverdue <= 90 => 'days_90',
            default => 'over_90',
        };
    }

    /**
     * Every line of the customer's account in one currency, oldest first;
     * on one day, what is owed before what was paid.
     *
     * @return Collection<int, array{date: string, order: int, kind: string, reference: string, description: string, debit: float, credit: float}>
     */
    private function entries(Organization $customer, string $currency): Collection
    {
        $entries = collect();

        foreach (Receivable::withoutTenantScope(fn () => Receivable::query()->where('org_id', $customer->id)->where('currency', $currency)->get()) as $receivable) {
            $entries->push($this->entry($receivable->issue_date->toDateString(), 0, 'invoice', $this->receivables->number($receivable), (string) $receivable->description, (float) $receivable->total, 0.0));
        }

        foreach (Payment::withoutTenantScope(fn () => Payment::query()->where('org_id', $customer->id)->where('currency', $currency)->get()) as $payment) {
            $entries->push($this->entry($payment->paid_at->toDateString(), 1, 'payment', (string) $payment->reference, $payment->method->label(), 0.0, (float) $payment->total));

            // Recorded in error and taken back: owed again from that day.
            if ($payment->reversed_at !== null) {
                $entries->push($this->entry($payment->reversed_at->toDateString(), 3, 'reversal', (string) $payment->reference, __('Payment reversed'), (float) $payment->total, 0.0));
            }
        }

        foreach (BillingCredit::withoutTenantScope(fn () => BillingCredit::query()->where('org_id', $customer->id)->where('currency', $currency)->whereIn('source', self::STATEMENT_CREDITS)->get()) as $credit) {
            $entries->push($this->entry($credit->granted_on->toDateString(), 2, 'credit', '', (string) ($credit->reason ?? __('Credit')), 0.0, (float) $credit->amount));
        }

        // A credit note owes the customer what it credits; money paid back
        // to it is owed no longer.
        foreach (Refund::withoutTenantScope(fn () => Refund::query()->where('org_id', $customer->id)->where('currency', $currency)->get()) as $refund) {
            if ((float) $refund->credited_amount > 0) {
                $entries->push($this->entry($refund->refunded_on->toDateString(), 2, 'credit_note', $this->numberOf($refund->credit_note_id), __('Credit note: :reason', ['reason' => $refund->reason]), 0.0, (float) $refund->credited_amount));
            }

            if ((float) $refund->payout_amount > 0) {
                $entries->push($this->entry($refund->refunded_on->toDateString(), 3, 'refund', (string) $refund->reference, __('Paid back to you'), (float) $refund->payout_amount, 0.0));
            }
        }

        foreach (Receivable::withoutTenantScope(fn () => Receivable::query()->where('org_id', $customer->id)->where('currency', $currency)->where('amount_written_off', '>', 0)->get()) as $receivable) {
            $entries->push($this->entry(CarbonImmutable::parse($receivable->written_off_at ?? $receivable->updated_at)->toDateString(), 4, 'write_off', $this->receivables->number($receivable), __('Written off'), 0.0, (float) $receivable->amount_written_off));
        }

        return $entries->sortBy([['date', 'asc'], ['order', 'asc']])->values();
    }

    private function numberOf(?string $invoiceId): string
    {
        return $invoiceId === null ? '' : (string) Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoiceId)->value('invoice_number'));
    }

    /**
     * @return array{date: string, order: int, kind: string, reference: string, description: string, debit: float, credit: float}
     */
    private function entry(string $date, int $order, string $kind, string $reference, string $description, float $debit, float $credit): array
    {
        return compact('date', 'order', 'kind', 'reference', 'description', 'debit', 'credit');
    }
}
