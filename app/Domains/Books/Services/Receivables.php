<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Models\BadDebtRelief;
use App\Domains\Books\Models\CustomerReceipt;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceAdjustments;
use App\Domains\Invoice\Support\BillingReferences;
use App\Domains\Organization\Services\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What an organization's customers owe it, as at any date: each issued
 * invoice's balance rebuilt from what had happened by then - the invoice
 * (issue date), the credit notes against it (their issue dates) and the
 * payments recorded on it (their payment dates, reversals included).
 *
 * As at a past date it is what was owed then, not what is owed now with
 * the old date on it, so a month-end ageing run weeks later gives the same
 * answer as one run on the day.
 *
 * An invoice, a prepayment invoice or a debit note is owed, less any
 * prepayment it deducts (Invoice::payable). A credit note takes away from
 * the invoices it names (billing_ref, by number or id, one or several -
 * BillingReferences). A note on several does not say how much is each's,
 * so it falls on each by its share of what they were all owed (spread()),
 * the last taking the halala left over: the credit is never counted on an
 * invoice and again as a credit of its own, so bad-debt relief on what is
 * left never takes back output tax the note already took back. One naming
 * nothing found is a credit of its own, below zero. Drafts, proformas and
 * documents the authority refused owe nothing. Amounts are in each
 * invoice's currency.
 *
 * A simplified invoice paid at the till - its payment means cash or a card
 * (UNTDID 4461: 10 cash, 48 bank card, 54 credit card, 55 debit card) - was
 * settled when it was issued: it is paid on its issue date, and a credit
 * note against it was refunded there too, so it is never owed.
 *
 * Of the branches the person sees (BranchScope), as every list of invoices
 * is: a cashier at one branch does not read another branch's customers'
 * balances, and the switcher narrows it further.
 *
 * What was written off under bad-debt relief (SalesBadDebts), and not paid
 * after all, is no longer owed as at the day it was written off.
 *
 * Built to scale to a till's volumes: one invoice's balance (balanceOf) is
 * read from its own payments, reliefs and the notes naming it, a handful of
 * queries however many invoices the organization has; the list of what is
 * still owed (open) leaves the till's sales out in the query, since they
 * never owe anything.
 */
class Receivables
{
    public const PAID = 'paid';

    public const PART_PAID = 'part_paid';

    public const UNPAID = 'unpaid';

    public const CREDIT = 'credit';

    /** Settled by credit notes alone: nothing was paid, so it is not paid. */
    public const CREDITED = 'credited';

    /** Written off as a bad debt: nothing is owed on it any more. */
    public const WRITTEN_OFF = 'written_off';

    /** Credit notes took part of it off, nothing was paid, and the rest is owed. */
    public const PART_CREDITED = 'part_credited';

    /** Payment means that settle a sale at the till (UNTDID 4461). */
    private const AT_SALE = ['10', '48', '54', '55'];

    /** Days past due each ageing bucket starts at, by key. */
    public const BUCKETS = ['current' => 0, '1_30' => 1, '31_60' => 31, '61_90' => 61, 'over_90' => 91];

    private const OWED = [DocumentType::Invoice, DocumentType::Prepayment, DocumentType::DebitNote];

    /** What a row reads of a document: enough for its balance and its age. */
    private const COLUMNS = ['id', 'org_id', 'branch_id', 'customer_id', 'invoice_number', 'type', 'document_type', 'status', 'payment_means_code',
        'billing_ref', 'issue_date', 'due_date', 'currency', 'exchange_rate', 'buyer_name', 'buyer_name_ar', 'total', 'prepaid_amount'];

    public function __construct(
        private readonly BranchScope $branches,
    ) {}

    /**
     * One row per document with something on it as at the date.
     *
     * @return Collection<int, array{invoice: Invoice, number: string, customer_id: string|null, customer: string, currency: string, issue_date: string, due_date: string, total: float, credited: float, paid: float, balance: float, days_overdue: int, bucket: string, status: string}>
     */
    public function asAt(string $organizationId, CarbonImmutable $date, ?string $customerId = null, bool $open = false): Collection
    {
        $until = $date->addDay()->toDateString();
        $owed = $this->branches->applyVisible($this->documents($organizationId, $until))
            ->whereIn('document_type', self::OWED)
            ->when($customerId !== null, fn (Builder $query) => $query->where('customer_id', $customerId))
            // Only what may still owe: a till's sale never does.
            ->when($open, fn (Builder $query) => $this->notAtTill($query))
            ->orderBy('issue_date')->orderBy('invoice_number')
            ->get(self::COLUMNS);
        $notes = $this->branches->applyVisible($this->documents($organizationId, $until))
            ->where('document_type', DocumentType::CreditNote)
            // A note on the tax invoice for a payment after bad-debt relief
            // takes back that invoice's tax (SalesBadDebts::undoRecovery),
            // not anything the customer owes.
            ->where(fn (Builder $named) => $named->whereNull('billing_ref')->orWhereNotIn('billing_ref', Invoice::query()
                ->select('invoice_number')->where('org_id', $organizationId)->whereNotNull('invoice_number')->whereIn('id', InvoiceAdjustments::recoveryInvoices())))
            // A note refunded at the till, against a till's sale, is
            // settled there too; one on several is sorted out below.
            ->when($open, fn (Builder $query) => $query->where(fn (Builder $named) => $named
                ->whereNull('billing_ref')
                ->orWhere(fn (Builder $other) => $other
                    ->whereNotIn('billing_ref', $this->tillSales($organizationId, 'invoice_number'))
                    ->whereNotIn('billing_ref', $this->tillSales($organizationId, 'id')))))
            ->orderBy('issue_date')->orderBy('invoice_number')
            ->get(self::COLUMNS);

        // What each named reference is: the invoices listed, then - once,
        // for the rest - any other the notes name (a till's sale, another
        // branch's), whose share of a note is not this list's to show.
        $targets = [];

        foreach ($owed as $invoice) {
            $targets[(string) $invoice->invoice_number] = $invoice;
            $targets[(string) $invoice->id] = $invoice;
        }

        $missing = array_values(array_diff(array_unique(array_merge([], ...$notes->map(fn (Invoice $note): array => BillingReferences::of($note->billing_ref))->all())), array_keys($targets)));

        foreach ($missing === [] ? [] : $this->named($organizationId, $until, $missing) as $invoice) {
            $targets[(string) $invoice->invoice_number] ??= $invoice;
            $targets[(string) $invoice->id] ??= $invoice;
        }

        $credited = [];
        $loose = [];

        foreach ($notes as $note) {
            $shares = $this->shares($note, $targets);

            if ($shares === []) {
                $loose[] = $note;

                continue;
            }

            foreach ($shares as $target => $share) {
                $credited[$target] = ($credited[$target] ?? 0.0) + $share;
            }
        }

        $ids = [...$owed->modelKeys(), ...array_map(fn (Invoice $note): string => (string) $note->id, $loose)];
        // One customer's few by id; otherwise summed for the organization in
        // the query, where a till's sale has no rows to sum.
        $paid = $this->paid($organizationId, $until, $customerId === null ? null : $ids);
        $writtenOff = $this->writtenOff($organizationId, $until, $customerId === null ? null : $owed->modelKeys());

        $rows = collect();

        foreach ($owed as $invoice) {
            $rows->push($this->balance($invoice, $date, $credited[(string) $invoice->id] ?? 0.0, $paid[(string) $invoice->id] ?? 0.0, $writtenOff[(string) $invoice->id] ?? 0.0));
        }

        foreach ($loose as $note) {
            // Paid back to the customer, a refund on the note is below zero.
            $rows->push($this->row($note, $date, -(float) $note->total, 0.0, $paid[(string) $note->id] ?? 0.0));
        }

        foreach ($this->onAccount($organizationId, $date, $customerId) as $row) {
            $rows->push($row);
        }

        return $rows
            ->filter(fn (array $row): bool => $customerId === null || $row['customer_id'] === $customerId)
            ->values();
    }

    /**
     * One document's row as at a date, read on its own: its payments and
     * reliefs, and the credit notes naming it with what each falls on it
     * (spread()) - a handful of queries, however many invoices there are.
     * Every branch's notes count: the invoice was found among those the
     * person may see, and what it owes is the customer's whole account.
     *
     * @return array<string, mixed>|null null for a document not issued by then
     */
    public function balanceOf(Invoice $invoice, CarbonImmutable $date): ?array
    {
        $until = $date->addDay()->toDateString();
        $organizationId = (string) $invoice->org_id;

        if (CarbonImmutable::parse($invoice->issue_date)->toDateString() >= $until) {
            return null;
        }

        $paid = $this->paid($organizationId, $until, [(string) $invoice->id])[(string) $invoice->id] ?? 0.0;

        if ($invoice->document_type === DocumentType::CreditNote) {
            return $this->row($invoice, $date, -(float) $invoice->total, 0.0, $paid);
        }

        $number = (string) $invoice->invoice_number;
        $id = (string) $invoice->id;
        $notes = $this->documents($organizationId, $until)
            ->where('document_type', DocumentType::CreditNote)
            ->where(fn (Builder $query) => $query
                ->whereIn('billing_ref', array_values(array_filter([$number, $id])))
                ->orWhere(fn (Builder $several) => $several
                    ->where(fn (Builder $listed) => $listed->where('billing_ref', 'like', '%,%')->orWhere('billing_ref', 'like', '%;%'))
                    ->where(fn (Builder $named) => $named->when($number !== '', fn (Builder $byNumber) => $byNumber->where('billing_ref', 'like', '%'.$number.'%'))->orWhere('billing_ref', 'like', '%'.$id.'%'))))
            ->get(self::COLUMNS);

        $references = array_values(array_unique(array_merge([], ...$notes->map(fn (Invoice $note): array => BillingReferences::of($note->billing_ref))->all())));
        $targets = [];

        foreach ($references === [] ? [] : $this->named($organizationId, $until, $references) as $named) {
            $targets[(string) $named->invoice_number] ??= $named;
            $targets[(string) $named->id] ??= $named;
        }

        $credited = 0.0;

        foreach ($notes as $note) {
            $credited += $this->shares($note, $targets)[$id] ?? 0.0;
        }

        return $this->balance($invoice, $date, $credited, $paid, $this->writtenOff($organizationId, $until, [$id])[$id] ?? 0.0);
    }

    /**
     * How a credit note on several invoices falls on each: by its share of
     * what they were all owed (Invoice::payable), in the order the note
     * names them, the last taking what rounding leaves. A note on one
     * invoice falls on it whole.
     *
     * @param  array<string, float>  $payables  invoice id => payable, in the order named
     * @return array<string, float> invoice id => share
     */
    public static function spread(float $total, array $payables): array
    {
        $ids = array_keys($payables);
        $sum = array_sum($payables);
        $shares = [];
        $given = 0.0;

        foreach ($ids as $index => $id) {
            $share = $index === count($ids) - 1 ? $total - $given : ($sum > 0 ? round($total * $payables[$id] / $sum, 2) : 0.0);
            $shares[$id] = round($share, 2) + 0.0;
            $given += $shares[$id];
        }

        return $shares;
    }

    /**
     * What a note takes off each invoice it names that is found among the
     * targets, by id; empty when it names none.
     *
     * @param  array<string, Invoice>  $targets  by number and by id
     * @return array<string, float>
     */
    private function shares(Invoice $note, array $targets): array
    {
        $payables = [];

        foreach (BillingReferences::of($note->billing_ref) as $reference) {
            $target = $targets[$reference] ?? null;

            if ($target !== null) {
                $payables[(string) $target->id] ??= (float) $target->payable();
            }
        }

        return self::spread((float) $note->total, $payables);
    }

    /**
     * Money customers paid on account and had left as at the date - what
     * came in, less refunds and what paid invoices (CustomerPayments) - a
     * credit of its own each, below zero, never overdue.
     *
     * @return list<array<string, mixed>>
     */
    private function onAccount(string $organizationId, CarbonImmutable $date, ?string $customerId): array
    {
        $until = $date->addDay()->toDateString();
        $held = $this->branches->applyVisible(CustomerReceipt::query())
            ->where('org_id', $organizationId)
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->where('received_on', '<', $until)
            ->selectRaw('COALESCE(receipt_id, id) as root, SUM(amount) as held')
            ->groupByRaw('COALESCE(receipt_id, id)')
            ->pluck('held', 'root');

        if ($held->isEmpty()) {
            return [];
        }

        $used = InvoicePayment::query()->where('org_id', $organizationId)->whereIn('receipt_id', $held->keys()->all())
            ->where('paid_on', '<', $until)
            ->selectRaw('receipt_id, SUM(amount) as used')->groupBy('receipt_id')->pluck('used', 'receipt_id');
        $left = $held->map(fn ($amount, $root): float => round((float) $amount - (float) ($used[$root] ?? 0), 2))->filter(fn (float $amount): bool => abs($amount) >= 0.005);
        $receipts = CustomerReceipt::query()->whereIn('id', $left->keys()->all())->orderBy('received_on')->get();
        $names = Customer::query()->withTrashed()->whereIn('id', $receipts->pluck('customer_id')->unique()->all())->pluck('name', 'id');
        $rows = [];

        foreach ($receipts as $receipt) {
            $received = $receipt->received_on->toDateString();
            $rows[] = [
                'invoice' => null,
                'receipt' => $receipt,
                'number' => __('On account').' '.($receipt->reference ?? $received),
                'customer_id' => (string) $receipt->customer_id,
                'customer' => (string) ($names[$receipt->customer_id] ?? ''),
                'currency' => (string) $receipt->currency,
                'issue_date' => $received,
                'due_date' => $received,
                'total' => -$left[$receipt->id] + 0.0,
                'credited' => 0.0,
                'paid' => 0.0,
                'written_off' => 0.0,
                'balance' => -$left[$receipt->id] + 0.0,
                'days_overdue' => 0,
                'bucket' => 'current',
                'status' => $left[$receipt->id] > 0 ? self::CREDIT : self::UNPAID,
            ];
        }

        return $rows;
    }

    /**
     * A row for an invoice: a till's sale was paid on issue, less what was
     * credited, and refunded there.
     *
     * @return array<string, mixed>
     */
    private function balance(Invoice $invoice, CarbonImmutable $date, float $credited, float $paid, float $writtenOff): array
    {
        $paid = $this->settledAtSale($invoice) ? (float) $invoice->payable() - $credited : $paid;

        return $this->row($invoice, $date, (float) $invoice->payable(), $credited, $paid, $writtenOff);
    }

    /**
     * The organization's issued documents by a date: drafts and refused
     * documents owe nothing, and the tax invoice for a payment after
     * bad-debt relief documents a payment on the original, owed nothing
     * more.
     *
     * @return Builder<Invoice>
     */
    private function documents(string $organizationId, string $until): Builder
    {
        return Invoice::acrossTenants()
            ->where('org_id', $organizationId)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Rejected])
            ->whereNotIn('id', InvoiceAdjustments::recoveryInvoices())
            ->where('issue_date', '<', $until);
    }

    /**
     * The invoices, of any branch, that these references name.
     *
     * @param  list<string>  $references
     * @return Collection<int, Invoice>
     */
    private function named(string $organizationId, string $until, array $references): Collection
    {
        return $this->documents($organizationId, $until)
            ->whereIn('document_type', self::OWED)
            ->where(fn (Builder $query) => $query->whereIn('invoice_number', $references)->orWhereIn('id', $references))
            ->get(self::COLUMNS);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function notAtTill(Builder $query): Builder
    {
        return $query->where(fn (Builder $other) => $other
            ->where('type', '!=', 'simplified')
            ->orWhereNull('payment_means_code')
            ->orWhereNotIn('payment_means_code', self::AT_SALE));
    }

    /**
     * A column of the till's sales, for a query to leave out the notes on them.
     *
     * @return Builder<Invoice>
     */
    private function tillSales(string $organizationId, string $column): Builder
    {
        return $this->documents($organizationId, '9999-12-31')->select($column)
            ->whereIn('document_type', self::OWED)
            ->whereNotNull($column)
            ->where('type', 'simplified')
            ->whereIn('payment_means_code', self::AT_SALE);
    }

    /**
     * What was paid on each document by the date, refunds and reversals
     * taking away: for these documents, or every one.
     *
     * @param  list<string>|null  $ids
     * @return array<string, float>
     */
    private function paid(string $organizationId, string $until, ?array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return InvoicePayment::acrossTenants()
            ->where('org_id', $organizationId)
            ->when($ids !== null, fn ($query) => $query->whereIn('invoice_id', $ids))
            ->where('paid_on', '<', $until)
            ->selectRaw('invoice_id, SUM(amount) as paid')
            ->groupBy('invoice_id')
            ->pluck('paid', 'invoice_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();
    }

    /**
     * What stands written off under bad-debt relief by the date, by invoice.
     *
     * @param  list<string>|null  $ids
     * @return array<string, float>
     */
    private function writtenOff(string $organizationId, string $until, ?array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return BadDebtRelief::acrossTenants()
            ->where('org_id', $organizationId)
            ->when($ids !== null, fn ($query) => $query->whereIn('invoice_id', $ids))
            ->where('happened_on', '<', $until)
            ->get(['invoice_id', 'kind', 'amount'])
            ->groupBy('invoice_id')
            ->map(fn ($rows): float => (float) $rows->sum(fn (BadDebtRelief $row): float => $row->signed()))
            ->all();
    }

    /**
     * What is still open, by currency and ageing bucket.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array<string, float>> currency => bucket => amount, with 'total'
     */
    public function ageing(Collection $rows): array
    {
        $ageing = [];

        foreach ($rows as $row) {
            if (abs($row['balance']) < 0.005) {
                continue;
            }

            $ageing[$row['currency']] ??= [...array_fill_keys(array_keys(self::BUCKETS), 0.0), 'total' => 0.0];
            $ageing[$row['currency']][$row['bucket']] += $row['balance'];
            $ageing[$row['currency']]['total'] += $row['balance'];
        }

        foreach ($ageing as $currency => $buckets) {
            $ageing[$currency] = array_map(fn (float $amount): float => round($amount, 2) + 0.0, $buckets);
        }

        return $ageing;
    }

    /** A simplified invoice paid in cash or by card at the till. */
    public function settledAtSale(Invoice $invoice): bool
    {
        $type = $invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type;

        return $type === 'simplified' && in_array((string) $invoice->payment_means_code, self::AT_SALE, true);
    }

    /** What one invoice owes today, for the payment form: below zero, a credit. */
    public function outstanding(Invoice $invoice): float
    {
        return $this->balanceOf($invoice, CarbonImmutable::now()->addYears(50))['balance'] ?? 0.0;
    }

    /**
     * @return array{invoice: Invoice, number: string, customer_id: string|null, customer: string, currency: string, issue_date: string, due_date: string, total: float, credited: float, paid: float, balance: float, days_overdue: int, bucket: string, status: string}
     */
    private function row(Invoice $invoice, CarbonImmutable $date, float $total, float $credited, float $paid, float $writtenOff = 0.0): array
    {
        $balance = round($total - $credited - $paid - $writtenOff, 2) + 0.0;
        $due = CarbonImmutable::parse($invoice->due_date ?? $invoice->issue_date)->startOfDay();
        $overdue = $balance > 0 ? max(0, (int) $due->diffInDays($date->startOfDay(), false)) : 0;
        $bucket = 'current';

        foreach (self::BUCKETS as $key => $from) {
            if ($overdue >= $from) {
                $bucket = $key;
            }
        }

        return [
            'invoice' => $invoice,
            'number' => (string) $invoice->invoice_number,
            'customer_id' => $invoice->customer_id === null ? null : (string) $invoice->customer_id,
            'customer' => (string) ($invoice->buyer_name ?: $invoice->buyer_name_ar),
            'currency' => (string) $invoice->currency,
            'issue_date' => CarbonImmutable::parse($invoice->issue_date)->toDateString(),
            'due_date' => $due->toDateString(),
            'total' => round($total, 2) + 0.0,
            'credited' => round($credited, 2) + 0.0,
            'paid' => round($paid, 2) + 0.0,
            'written_off' => round($writtenOff, 2) + 0.0,
            'balance' => $balance,
            'days_overdue' => $overdue,
            'bucket' => $bucket,
            'status' => match (true) {
                $balance < -0.005 => self::CREDIT,
                $balance < 0.005 && $writtenOff > 0.005 => self::WRITTEN_OFF,
                $balance < 0.005 && $paid < 0.005 && $credited > 0 => self::CREDITED,
                $balance < 0.005 => self::PAID,
                $paid > 0 => self::PART_PAID,
                $credited > 0 => self::PART_CREDITED,
                default => self::UNPAID,
            },
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::PAID => __('Paid'),
            self::PART_PAID => __('Part paid'),
            self::CREDIT => __('Credit'),
            self::CREDITED => __('Credited'),
            self::PART_CREDITED => __('Part credited'),
            self::WRITTEN_OFF => __('Written off'),
            default => __('Unpaid'),
        };
    }

    /**
     * A row's age as its list shows it: a document with nothing left owed is
     * settled, not "not yet due", however recent its due date.
     *
     * @param  array{balance: float, bucket: string}  $row
     */
    public static function ageLabel(array $row): string
    {
        return match (true) {
            abs($row['balance']) < 0.005 => __('Settled'),
            $row['balance'] < 0 => __('Nothing owed'),
            default => self::bucketLabel($row['bucket']),
        };
    }

    public static function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            '1_30' => __('1-30 days'),
            '31_60' => __('31-60 days'),
            '61_90' => __('61-90 days'),
            'over_90' => __('Over 90 days'),
            default => __('Not yet due'),
        };
    }
}
