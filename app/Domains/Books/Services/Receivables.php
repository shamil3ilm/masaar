<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Models\BadDebtRelief;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceAdjustments;
use App\Domains\Organization\Services\BranchScope;
use Carbon\CarbonImmutable;
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
 * the invoice it names (billing_ref, by number or id); one naming nothing
 * found is a credit of its own, below zero. Drafts, proformas and documents
 * the authority refused owe nothing. Amounts are in each invoice's
 * currency.
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

    public function __construct(
        private readonly BranchScope $branches,
    ) {}

    /**
     * One row per document with something on it as at the date.
     *
     * @return Collection<int, array{invoice: Invoice, number: string, customer_id: string|null, customer: string, currency: string, issue_date: string, due_date: string, total: float, credited: float, paid: float, balance: float, days_overdue: int, bucket: string, status: string}>
     */
    public function asAt(string $organizationId, CarbonImmutable $date, ?string $customerId = null): Collection
    {
        $until = $date->addDay()->toDateString();
        $documents = $this->branches->applyVisible(Invoice::acrossTenants())
            ->where('org_id', $organizationId)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Rejected])
            ->whereIn('document_type', [...self::OWED, DocumentType::CreditNote])
            // Paid on the original: the tax invoice for a payment after
            // bad-debt relief documents it and is owed nothing more.
            ->whereNotIn('id', InvoiceAdjustments::recoveryInvoices())
            ->where('issue_date', '<', $until)
            ->orderBy('issue_date')->orderBy('invoice_number')
            ->get();

        $owed = $documents->filter(fn (Invoice $invoice): bool => $invoice->document_type !== DocumentType::CreditNote);
        $byNumber = [];

        foreach ($owed as $invoice) {
            $byNumber[(string) $invoice->invoice_number] = (string) $invoice->id;
            $byNumber[(string) $invoice->id] = (string) $invoice->id;
        }

        $credited = [];
        $loose = [];

        foreach ($documents->where('document_type', DocumentType::CreditNote) as $note) {
            $target = $byNumber[(string) $note->billing_ref] ?? null;

            if ($target === null) {
                $loose[] = $note;

                continue;
            }

            $credited[$target] = ($credited[$target] ?? 0.0) + (float) $note->total;
        }

        $paid = InvoicePayment::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('paid_on', '<', $until)
            ->selectRaw('invoice_id, SUM(amount) as paid')
            ->groupBy('invoice_id')
            ->pluck('paid', 'invoice_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();

        $writtenOff = BadDebtRelief::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('happened_on', '<', $until)
            ->get(['invoice_id', 'kind', 'amount'])
            ->groupBy('invoice_id')
            ->map(fn ($rows): float => (float) $rows->sum(fn (BadDebtRelief $row): float => ($row->kind === BadDebtRelief::RELIEF ? 1 : -1) * (float) $row->amount))
            ->all();

        $rows = collect();

        foreach ($owed as $invoice) {
            $creditedOn = $credited[(string) $invoice->id] ?? 0.0;
            $paidOn = $this->settledAtSale($invoice)
                ? (float) $invoice->payable() - $creditedOn
                : ($paid[(string) $invoice->id] ?? 0.0);
            $rows->push($this->row($invoice, $date, (float) $invoice->payable(), $creditedOn, $paidOn, $writtenOff[(string) $invoice->id] ?? 0.0));
        }

        foreach ($loose as $note) {
            $rows->push($this->row($note, $date, -(float) $note->total, 0.0, 0.0));
        }

        return $rows
            ->filter(fn (array $row): bool => $customerId === null || $row['customer_id'] === $customerId)
            ->values();
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

    /** What one invoice owes today, for the payment form. */
    public function outstanding(Invoice $invoice): float
    {
        // Its own branch, whatever the switcher shows: the invoice was
        // already found among those the person may see.
        $row = $this->branches->within([(string) ($invoice->branch_id ?? BranchScope::NONE)], fn (): Collection => $this->asAt((string) $invoice->org_id, CarbonImmutable::now()->addYears(50)))
            ->first(fn (array $row): bool => (string) $row['invoice']->id === (string) $invoice->id);

        return $row['balance'] ?? 0.0;
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
