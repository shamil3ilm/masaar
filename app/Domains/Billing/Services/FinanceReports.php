<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * Admin › Finance › Reports: what the platform invoiced its customers, what
 * it collected and how, the VAT on its own invoices, and what is owed by age.
 *
 * Each report is for a period of days, both ends counted, every amount in
 * its own currency; the VAT report also in the tax currency at each
 * document's recorded exchange rate, which is the figure the platform's own
 * VAT return takes (ZATCA XML Implementation Standard BT-111: tax totals in
 * SAR). Dates are compared half-open to the day after, so a column holding
 * a date and one holding midnight read the same on SQLite and MySQL.
 */
class FinanceReports
{
    public const REPORTS = ['receivables', 'payments', 'vat', 'ageing', 'collections'];

    public function __construct(
        private readonly PlatformInvoices $platform,
        private readonly Statements $statements,
    ) {}

    /**
     * Invoiced in the period, by currency: how many, their net, VAT and
     * total, and what of them is paid, settled by credit, credited, written
     * off and still owed.
     *
     * Paid is money received; settled by credit is the customer's credit
     * taken. A credit note's leftover kept as credit was received once, on
     * the invoice it credits, so a second invoice taking it is not paid
     * again (the same split as collections()).
     *
     * Each as it stood at the end of the period (Statements::standingOn),
     * so a past month reads the same however much was paid since.
     *
     * @return array<string, array<string, int|string>>
     */
    public function receivables(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $receivables = $this->inPeriod(Receivable::query()->withoutGlobalScopes(), 'issue_date', $from, $to)->get();
        $standing = $this->statements->standingOn($receivables, $to);

        foreach ($receivables as $receivable) {
            $at = $standing[$receivable->id];
            $row = &$rows[(string) $receivable->currency];
            $row ??= ['count' => 0, 'net' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'by_credit' => 0.0, 'credited' => 0.0, 'written_off' => 0.0, 'outstanding' => 0.0];
            $row['count']++;
            $row['net'] += (float) $receivable->subtotal;
            $row['vat'] += (float) $receivable->vat_amount;
            $row['total'] += (float) $receivable->total;
            $row['paid'] += $at['paid'];
            $row['by_credit'] += $at['by_credit'];
            $row['credited'] += $at['credited'];
            $row['written_off'] += $at['written_off'];
            $row['outstanding'] += $at['balance'];
            unset($row);
        }

        return $this->money($rows);
    }

    /**
     * Received in the period, by method and currency: how many, the amount
     * credited to customers, the fees the bank or gateway kept, and what
     * that leaves; and what was paid back.
     *
     * @return list<array<string, int|string>>
     */
    public function payments(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        // A payment reversed was recorded in error: it was never received.
        foreach ($this->inPeriod(Payment::query()->withoutGlobalScopes()->whereNull('reversed_at'), 'paid_at', $from, $to)->get() as $payment) {
            $key = $payment->method->value.'|'.$payment->currency;
            $rows[$key] ??= ['method' => $payment->method->label(), 'channel' => $payment->channel?->label() ?? '', 'currency' => (string) $payment->currency, 'count' => 0, 'received' => 0.0, 'fees' => 0.0, 'net' => 0.0, 'paid_back' => 0.0];
            $rows[$key]['count']++;
            $rows[$key]['received'] += (float) $payment->total;
            $rows[$key]['fees'] += (float) $payment->fee_amount;
            $rows[$key]['net'] += (float) $payment->total - (float) $payment->fee_amount;
        }

        foreach ($this->inPeriod(Refund::query()->withoutGlobalScopes(), 'refunded_on', $from, $to)->where('payout_amount', '>', 0)->get() as $refund) {
            $key = 'refund:'.$refund->payout_method.'|'.$refund->currency;
            $rows[$key] ??= ['method' => __('Paid back: :method', ['method' => $refund->payout_method === 'card' ? __('Card') : __('Bank transfer')]), 'channel' => '', 'currency' => (string) $refund->currency, 'count' => 0, 'received' => 0.0, 'fees' => 0.0, 'net' => 0.0, 'paid_back' => 0.0];
            $rows[$key]['count']++;
            $rows[$key]['paid_back'] += (float) $refund->payout_amount;
            $rows[$key]['net'] -= (float) $refund->payout_amount;
        }

        ksort($rows);

        return array_values(array_map(fn (array $row): array => [...$row, ...array_map(fn ($value): string => Receivable::money((float) $value), array_intersect_key($row, array_flip(['received', 'fees', 'net', 'paid_back'])))], $rows));
    }

    /**
     * VAT on the platform's own documents issued in the period, document by
     * document: invoices add, credit notes take away. Each in its currency
     * and in the tax currency at its recorded rate.
     *
     * @return array{documents: list<array<string, string>>, totals: array<string, string>, tax_currency: string}
     */
    public function vat(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seller = $this->platform->seller();
        $taxCurrency = $this->platform->taxCurrency();
        $documents = [];
        $totals = ['net' => 0.0, 'vat' => 0.0];

        $invoices = $seller === null ? collect() : Invoice::withoutTenantScope(fn () => $this->inPeriod(Invoice::query(), 'issue_date', $from, $to)
            ->where('org_id', $seller->id)
            // Issued, in whichever country the billing company is: a Saudi
            // document has a hash, a UAE one never does, and both leave draft.
            ->where('status', '!=', InvoiceStatus::Draft)
            // Refused by the authority, it is no tax document: as VatSummary.
            ->where('status', '!=', InvoiceStatus::Rejected)
            ->whereIn('document_type', ['invoice', 'credit_note', 'debit_note'])
            ->orderBy('issue_date')
            ->orderBy('invoice_number')
            ->get());

        foreach ($invoices as $invoice) {
            $sign = $invoice->document_type->value === 'credit_note' ? -1 : 1;
            $rate = strtoupper((string) $invoice->currency) === $taxCurrency ? 1.0 : (float) $invoice->exchange_rate;
            // What the VAT is on: the total less its tax, after any discount
            // and charge on the whole document.
            $net = $sign * ((float) $invoice->total - (float) $invoice->tax_amount);
            $vat = $sign * (float) $invoice->tax_amount;
            $vatTax = $sign * round((float) $invoice->tax_amount * $rate, 2);
            $netTax = round($net * $rate, 2);

            $documents[] = [
                'number' => (string) $invoice->invoice_number,
                'date' => $invoice->issue_date->toDateString(),
                'type' => $invoice->document_type->value,
                'customer' => (string) $invoice->buyer_name,
                'currency' => (string) $invoice->currency,
                'net' => Receivable::money($net),
                'vat' => Receivable::money($vat),
                'rate' => $rate === 1.0 ? '1' : rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.'),
                'net_tax' => Receivable::money($netTax),
                'vat_tax' => Receivable::money($vatTax),
            ];
            $totals['net'] += $netTax;
            $totals['vat'] += $vatTax;
        }

        return ['documents' => $documents, 'totals' => array_map(fn (float $value): string => Receivable::money($value), $totals), 'tax_currency' => $taxCurrency];
    }

    /**
     * Collections in the period, by currency: what fell due, what was
     * collected against invoices (by payment and by credit), what was
     * written off, how long the invoices paid off in it took, and what is
     * still owed on invoices that fell due before its end.
     *
     * @return array<string, array<string, int|string>>
     */
    public function collections(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $blank = fn (): array => ['fell_due' => 0.0, 'collected' => 0.0, 'by_credit' => 0.0, 'written_off' => 0.0, 'paid_count' => 0, 'days_to_pay' => 0.0, 'overdue' => 0.0, 'overdue_count' => 0];

        foreach ($this->inPeriod(Receivable::query()->withoutGlobalScopes(), 'due_date', $from, $to)->get() as $receivable) {
            $rows[(string) $receivable->currency] ??= $blank();
            $rows[(string) $receivable->currency]['fell_due'] += (float) $receivable->total - (float) $receivable->amount_credited;
        }

        foreach ($this->inPeriod(PaymentAllocation::query()->withoutGlobalScopes()->with(['receivable' => fn ($query) => $query->withoutGlobalScopes()]), 'allocated_on', $from, $to)->get() as $allocation) {
            $currency = (string) $allocation->receivable?->currency;
            $rows[$currency] ??= $blank();
            $rows[$currency][$allocation->credit_id === null ? 'collected' : 'by_credit'] += (float) $allocation->amount;
        }

        foreach ($this->inPeriod(Receivable::query()->withoutGlobalScopes(), 'written_off_at', $from, $to)->get() as $receivable) {
            $rows[(string) $receivable->currency] ??= $blank();
            $rows[(string) $receivable->currency]['written_off'] += (float) $receivable->amount_written_off;
        }

        foreach ($this->inPeriod(Receivable::query()->withoutGlobalScopes(), 'paid_on', $from, $to)->where('status', ReceivableStatus::Paid->value)->get() as $receivable) {
            $rows[(string) $receivable->currency] ??= $blank();
            $rows[(string) $receivable->currency]['paid_count']++;
            $rows[(string) $receivable->currency]['days_to_pay'] += max(0, (int) CarbonImmutable::parse($receivable->issue_date->toDateString())->diffInDays(CarbonImmutable::parse($receivable->paid_on->toDateString())));
        }

        foreach ($this->overdueAt($to) as [$receivable, $owed]) {
            $rows[(string) $receivable->currency] ??= $blank();
            $rows[(string) $receivable->currency]['overdue'] += $owed;
            $rows[(string) $receivable->currency]['overdue_count']++;
        }

        ksort($rows);

        return array_map(fn (array $row): array => [
            'fell_due' => Receivable::money($row['fell_due']),
            'collected' => Receivable::money($row['collected']),
            'by_credit' => Receivable::money($row['by_credit']),
            'written_off' => Receivable::money($row['written_off']),
            'average_days_to_pay' => $row['paid_count'] === 0 ? '' : number_format($row['days_to_pay'] / $row['paid_count'], 1, '.', ''),
            'overdue' => Receivable::money($row['overdue']),
            'overdue_count' => $row['overdue_count'],
        ], $rows);
    }

    /**
     * The platform's own invoices issued with no receivable behind them: a
     * step after issuing failed (Receivables::issue), or a plan payment's
     * invoice could not be put on the account (settledByPlanPayment). Each
     * is a tax document the customer owes on that the books do not show.
     *
     * @return list<array{id: string, number: string, date: string, customer: string, currency: string, total: string}>
     */
    public function unrecorded(): array
    {
        $seller = $this->platform->seller();

        if ($seller === null) {
            return [];
        }

        return Invoice::withoutTenantScope(fn () => Invoice::query()
            ->where('org_id', $seller->id)
            ->where('status', '!=', InvoiceStatus::Draft)
            ->where('status', '!=', InvoiceStatus::Rejected)
            ->where('document_type', 'invoice')
            ->whereNotIn('id', Receivable::query()->withoutGlobalScopes()->select('invoice_id'))
            ->orderBy('issue_date')
            ->get())
            ->map(fn (Invoice $invoice): array => [
                'id' => (string) $invoice->id,
                'number' => (string) $invoice->invoice_number,
                'date' => $invoice->issue_date->toDateString(),
                'customer' => (string) $invoice->buyer_name,
                'currency' => (string) $invoice->currency,
                'total' => Receivable::money((float) $invoice->total),
            ])->values()->all();
    }

    /**
     * @return array<string, array{rows: array<string, array<string, mixed>>, totals: array<string, string>}>
     */
    public function ageing(CarbonImmutable $on): array
    {
        return $this->statements->ageing($on);
    }

    /**
     * Still owed at the end of a day and past due by then, as it stood
     * that day: each with what it owed.
     *
     * @return list<array{0: Receivable, 1: float}>
     */
    private function overdueAt(CarbonImmutable $on): array
    {
        $due = Receivable::query()->withoutGlobalScopes()->where('due_date', '<', $on->toDateString())->get();
        $standing = $this->statements->standingOn($due, $on);

        return $due->filter(fn (Receivable $receivable): bool => $standing[$receivable->id]['balance'] > 0)
            ->map(fn (Receivable $receivable): array => [$receivable, $standing[$receivable->id]['balance']])
            ->values()->all();
    }

    /**
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function inPeriod($query, string $column, CarbonImmutable $from, CarbonImmutable $to)
    {
        return $query->where($column, '>=', $from->toDateString())->where($column, '<', $to->addDay()->toDateString());
    }

    /**
     * @param  array<string, array<string, int|float>>  $rows
     * @return array<string, array<string, int|string>>
     */
    private function money(array $rows): array
    {
        ksort($rows);

        return array_map(fn (array $row): array => array_map(fn ($value) => is_float($value) ? Receivable::money($value) : $value, $row), $rows);
    }
}
