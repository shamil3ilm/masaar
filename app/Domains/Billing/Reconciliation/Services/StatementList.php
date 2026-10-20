<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Services;

use App\Domains\Billing\Enums\PaymentChannel;
use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatchItem;
use App\Domains\Billing\Reconciliation\Models\StatementImport;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reconciliation as the console shows it: the statements brought in, their
 * lines with the list toolkit, one line's workbench, and the lines and
 * matches that need an eye - unmatched, short and over.
 */
class StatementList
{
    public function __construct(
        private readonly Receivables $receivables,
        private readonly Ledger $ledger,
        private readonly FinanceSettings $settings,
    ) {}

    /** The statements brought in, the latest first. */
    public function imports(): LengthAwarePaginator
    {
        return StatementImport::query()->latest()->paginate(25);
    }

    public function spec(): ListSpec
    {
        $currencies = FinanceSettings::billable();
        $sources = collect($this->settings->bankAccounts())->mapWithKeys(fn (array $account): array => [$account['key'] => $account['bank_name'].' ('.$account['currency'].')'])
            ->put(StatementImport::GATEWAY, __('Card gateway'))
            ->all();

        return new ListSpec(
            key: 'admin.finance.reconciliation.lines',
            sorts: [
                'booked' => new Sort(__('Date'), 'booked_on'),
                'amount' => new Sort(__('Amount'), 'amount'),
            ],
            defaultSort: 'booked',
            filters: [
                new Search('q', __('Search'), ['reference', 'bank_reference', 'description', 'counterparty_name', 'gateway_reference'], __('Reference, payer or text')),
                new Choice('status', __('Status'), LineStatus::labels(), 'status'),
                new Choice('direction', __('Direction'), [ParsedLine::CREDIT => __('Money in'), ParsedLine::DEBIT => __('Money out')], 'direction'),
                new Choice('currency', __('Currency'), array_combine($currencies, $currencies), 'currency'),
                new Choice('source', __('Account'), $sources, 'source'),
                new DateRange('booked_on', __('Booked')),
            ],
        );
    }

    public function lines(ListState $list): LengthAwarePaginator
    {
        return $list->paginate(StatementLine::query());
    }

    public function csv(ListState $list): StreamedResponse
    {
        $statuses = LineStatus::labels();

        return CsvExport::download('statement-lines-'.now()->format('Y-m-d').'.csv', [
            __('Date') => fn (StatementLine $line): string => $line->booked_on->toDateString(),
            __('Direction') => fn (StatementLine $line): string => $line->isCredit() ? __('Money in') : __('Money out'),
            __('Currency') => fn (StatementLine $line): string => (string) $line->currency,
            __('Amount') => fn (StatementLine $line): string => (string) $line->amount,
            __('Fee') => fn (StatementLine $line): string => (string) $line->fee_amount,
            __('Reference') => fn (StatementLine $line): string => (string) $line->reference,
            __('Bank reference') => fn (StatementLine $line): string => (string) $line->bank_reference,
            __('Payer') => fn (StatementLine $line): string => (string) $line->counterparty_name,
            __('Description') => fn (StatementLine $line): string => (string) $line->description,
            __('Status') => fn (StatementLine $line): string => $statuses[$line->status->value] ?? $line->status->value,
        ], $list->apply(StatementLine::query())->lazy(500));
    }

    public function line(string $lineId): ?StatementLine
    {
        return StatementLine::query()->with('statementImport')->find($lineId);
    }

    public function findMatch(string $matchId): ?ReconciliationMatch
    {
        return ReconciliationMatch::withoutTenantScope(fn () => ReconciliationMatch::query()->find($matchId));
    }

    /** The first line a match joins: where its workbench is. */
    public function firstLineOf(ReconciliationMatch $match): ?StatementLine
    {
        return StatementLine::query()->find($match->items()->where('role', ReconciliationMatchItem::LINE)->value('statement_line_id'));
    }

    /**
     * Every match of one line, the newest first, each with what it joins
     * worded for the screen.
     *
     * @return Collection<int, array{match: ReconciliationMatch, customer: string, targets: list<array{kind: string, id: string, label: string, amount: string}>, lines: int}>
     */
    public function matchesOf(StatementLine $line): Collection
    {
        $matches = ReconciliationMatch::withoutTenantScope(fn () => ReconciliationMatch::query()
            ->with(['organization', 'items.receivable' => fn ($query) => $query->withoutGlobalScopes(), 'items.payment' => fn ($query) => $query->withoutGlobalScopes(), 'items.refund' => fn ($query) => $query->withoutGlobalScopes()])
            ->whereHas('items', fn ($query) => $query->where('statement_line_id', $line->id))
            ->orderByRaw("case status when 'suggested' then 0 when 'confirmed' then 1 else 2 end")
            ->orderByDesc('confidence')
            ->latest()
            ->get());

        return $matches->map(fn (ReconciliationMatch $match): array => $this->describe($match));
    }

    /**
     * Who the line could be from, for matching by hand: the customers owed
     * in its currency or with a payment no line has claimed; and for the
     * one chosen, its invoices and those payments.
     *
     * @return array{customers: array<string, string>, invoices: Collection<int, array<string, mixed>>, payments: Collection<int, mixed>}
     */
    public function manualOptions(StatementLine $line, ?string $organizationId): array
    {
        $owed = $this->ledger->owed((string) $line->currency);
        $payments = $this->ledger->unclaimed((string) $line->currency, $line->source === StatementImport::GATEWAY ? PaymentChannel::Gateway : PaymentChannel::Bank);
        $customers = [];

        foreach ($owed as $row) {
            $customers[(string) $row['receivable']->org_id] = (string) $row['receivable']->organization?->name;
        }

        foreach ($payments as $payment) {
            $customers[(string) $payment->org_id] = (string) $payment->organization?->name;
        }

        asort($customers);

        return [
            'customers' => $customers,
            'invoices' => $organizationId === null ? collect() : $owed->filter(fn (array $row): bool => (string) $row['receivable']->org_id === $organizationId)->values(),
            'payments' => $organizationId === null ? collect() : $payments->filter(fn ($payment): bool => (string) $payment->org_id === $organizationId)->values(),
        ];
    }

    /**
     * Lines of money in that nothing is confirmed for, the oldest first.
     */
    public function unmatched(): LengthAwarePaginator
    {
        return StatementLine::query()
            ->where('direction', ParsedLine::CREDIT)
            ->whereIn('status', [LineStatus::Unmatched->value, LineStatus::Suggested->value])
            ->orderBy('booked_on')
            ->orderBy('sequence')
            ->paginate(25, ['*'], 'unmatched');
    }

    /**
     * Confirmed matches that left invoices short (part paid), or paid more
     * than they owed (kept to the customer's credit).
     *
     * @return Collection<int, array{match: ReconciliationMatch, customer: string, targets: list<array{kind: string, id: string, label: string, amount: string}>, lines: int}>
     */
    public function exceptions(bool $short): Collection
    {
        return ReconciliationMatch::withoutTenantScope(fn () => $this->confirmed()
            ->with(['organization', 'items.receivable' => fn ($query) => $query->withoutGlobalScopes(), 'items.payment' => fn ($query) => $query->withoutGlobalScopes()])
            ->when($short, fn (Builder $query) => $query->whereRaw('(difference + coalesce(fee_amount, 0)) < -0.004'),
                fn (Builder $query) => $query->where('difference', '>', 0.004))
            ->latest('confirmed_at')
            ->limit(100)
            ->get())
            ->map(fn (ReconciliationMatch $match): array => $this->describe($match));
    }

    /**
     * Each currency's lines by where they stand, and what the confirmed
     * matches took as fees, left short and kept over.
     *
     * @return array<string, array<string, int|string>>
     */
    public function summary(): array
    {
        $rows = [];
        $blank = fn (): array => [
            'in_count' => 0, 'in_amount' => '0.00', 'out_count' => 0, 'out_amount' => '0.00',
            'matched_count' => 0, 'matched_amount' => '0.00', 'suggested_count' => 0, 'suggested_amount' => '0.00',
            'unmatched_count' => 0, 'unmatched_amount' => '0.00', 'ignored_count' => 0, 'ignored_amount' => '0.00',
            'auto' => 0, 'by_admin' => 0, 'reversed' => 0, 'fees' => '0.00', 'short' => '0.00', 'over' => '0.00',
        ];

        $lines = StatementLine::query()
            ->selectRaw('currency, direction, status, count(*) as lines_count, sum(amount) as lines_amount')
            ->groupBy('currency', 'direction', 'status')
            ->get();

        foreach ($lines as $group) {
            $row = $rows[(string) $group->currency] ?? $blank();
            $count = (int) $group->getAttribute('lines_count');
            $amount = number_format((float) $group->getAttribute('lines_amount'), 2, '.', '');

            if ($group->direction === ParsedLine::DEBIT) {
                [$row['out_count'], $row['out_amount']] = [$row['out_count'] + $count, bcadd($row['out_amount'], $amount, 2)];
            } else {
                $status = $group->status->value;
                [$row['in_count'], $row['in_amount']] = [$row['in_count'] + $count, bcadd($row['in_amount'], $amount, 2)];
                [$row[$status.'_count'], $row[$status.'_amount']] = [$row[$status.'_count'] + $count, bcadd($row[$status.'_amount'], $amount, 2)];
            }

            $rows[(string) $group->currency] = $row;
        }

        $matches = ReconciliationMatch::withoutTenantScope(fn () => ReconciliationMatch::query()
            ->whereIn('status', [MatchStatus::Confirmed->value, MatchStatus::Reversed->value])
            ->get(['currency', 'status', 'auto_confirmed', 'difference', 'fee_amount']));

        foreach ($matches as $match) {
            $row = $rows[(string) $match->currency] ?? $blank();

            if ($match->status === MatchStatus::Reversed) {
                $row['reversed']++;
            } else {
                $row[$match->auto_confirmed ? 'auto' : 'by_admin']++;
                $row['fees'] = bcadd($row['fees'], (string) ($match->fee_amount ?? '0'), 2);
                $row['short'] = $match->isShort() ? bcadd($row['short'], number_format(-((float) $match->difference + (float) $match->fee_amount), 2, '.', ''), 2) : $row['short'];
                $row['over'] = $match->isOver() ? bcadd($row['over'], (string) $match->difference, 2) : $row['over'];
            }

            $rows[(string) $match->currency] = $row;
        }

        ksort($rows);

        return $rows;
    }

    /** @return Builder<ReconciliationMatch> */
    private function confirmed(): Builder
    {
        return ReconciliationMatch::query()->where('status', MatchStatus::Confirmed->value);
    }

    /**
     * @return array{match: ReconciliationMatch, customer: string, targets: list<array{kind: string, id: string, label: string, amount: string}>, lines: int}
     */
    private function describe(ReconciliationMatch $match): array
    {
        $receivables = $match->items->where('role', ReconciliationMatchItem::RECEIVABLE)->map(fn (ReconciliationMatchItem $item) => $item->receivable)->filter();
        $numbers = $this->receivables->numbers($receivables);
        $targets = [];

        foreach ($match->items as $item) {
            $payment = (string) ($item->payment?->reference ?? $item->payment?->gateway_reference);
            $target = match ($item->role) {
                ReconciliationMatchItem::RECEIVABLE => ['kind' => 'invoice', 'id' => (string) $item->receivable_id, 'label' => $numbers[(string) $item->receivable_id] ?? ''],
                ReconciliationMatchItem::PAYMENT => ['kind' => 'payment', 'id' => (string) $item->payment_id, 'label' => $payment],
                ReconciliationMatchItem::CREATED => ['kind' => 'created', 'id' => (string) $item->payment_id, 'label' => $payment],
                ReconciliationMatchItem::REFUND => ['kind' => 'refund', 'id' => (string) $item->refund_id, 'label' => (string) ($item->refund?->reference ?? $item->refund?->gateway_reference ?? $item->refund?->refunded_on?->toDateString())],
                default => null,
            };

            if ($target !== null) {
                $targets[] = [...$target, 'amount' => (string) $item->amount];
            }
        }

        return [
            'match' => $match,
            'customer' => (string) $match->organization?->name,
            'targets' => $targets,
            'lines' => $match->items->where('role', ReconciliationMatchItem::LINE)->count(),
        ];
    }
}
