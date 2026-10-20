<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Reconciliation\DTOs\Candidate;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\MatchRule;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatchItem as Item;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Services\Payments;
use App\Domains\Billing\Services\Receivables;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What an admin does with statement lines: confirm a suggestion, reject
 * it, split it, match a line by hand, set a line aside or bring it back,
 * and undo a confirmed match.
 *
 * Confirming never records money twice. A line matched to a payment
 * already recorded only claims it. A line matched to invoices records the
 * payment through Payments, as an admin recording the transfer would -
 * allocations, the bank's or the gateway's fee, the exchange difference,
 * and anything over kept to the customer's credit - and the match keeps
 * which payment it made. Undoing the match reverses that payment
 * (Payments::reverse); one it only claimed is left as it was. The issued
 * invoice is never touched: only what is owed on it moves.
 *
 * A suggestion below Matcher::HIGH is confirmed only by an admin who says
 * they checked it. Every move is in the audit log, by the customer's.
 */
class Reconciliation
{
    public function __construct(
        private readonly Payments $payments,
        private readonly Receivables $receivables,
        private readonly Ledger $ledger,
        private readonly PaymentRecorder $recorder,
        private readonly Billing $billing,
        private readonly AuditService $audit,
    ) {}

    /** Keep a suggestion; its lines are suggested from now on. */
    public function suggest(Candidate $candidate, ?string $actorId = null): ReconciliationMatch
    {
        return DB::transaction(function () use ($candidate, $actorId): ReconciliationMatch {
            $match = new ReconciliationMatch([
                'org_id' => $candidate->organizationId,
                'status' => MatchStatus::Suggested,
                'rule' => $candidate->rule,
                'confidence' => $candidate->confidence,
                'basis' => $candidate->basis,
                'currency' => $candidate->currency,
                'lines_amount' => $candidate->linesAmount(),
                'targets_amount' => $candidate->targetsAmount(),
                'difference' => $candidate->difference(),
                'fee_amount' => $candidate->fee,
            ]);
            $match->forceFill(['created_by' => $actorId]);
            ReconciliationMatch::withoutTenantScope(fn () => $match->save());

            foreach ([Item::LINE => $candidate->lines, Item::RECEIVABLE => $candidate->receivables, Item::PAYMENT => $candidate->payments] as $role => $amounts) {
                foreach ($amounts as $id => $amount) {
                    $this->item($match, $role, $amount, ...[match ($role) {
                        Item::LINE => 'lineId',
                        Item::RECEIVABLE => 'receivableId',
                        default => 'paymentId',
                    } => (string) $id]);
                }
            }

            StatementLine::query()->whereKey(array_keys($candidate->lines))->where('status', LineStatus::Unmatched->value)
                ->update(['status' => LineStatus::Suggested->value, 'updated_at' => now()]);

            return $match;
        });
    }

    /**
     * Confirm a suggestion: claim the payments it names, or record the
     * payment for each line against the invoices it names.
     *
     * @param  bool  $checked  the admin says they checked one below Matcher::HIGH
     *
     * @throws DomainException saying why it cannot be confirmed
     */
    public function confirm(ReconciliationMatch $match, ?string $actorId, bool $auto = false, bool $checked = false): ReconciliationMatch
    {
        return DB::transaction(function () use ($match, $actorId, $auto, $checked): ReconciliationMatch {
            $locked = $this->lock($match);

            if ($locked->status !== MatchStatus::Suggested) {
                throw new DomainException(__('This match is no longer waiting to be confirmed.'));
            }

            if ($locked->confidence < Matcher::HIGH && ($auto || ! $checked)) {
                throw new DomainException(__('This match is only :confidence% sure. Confirm that you checked it against the statement.', ['confidence' => $locked->confidence]));
            }

            $items = $locked->items()->get();
            $lines = $this->openLines($items->where('role', Item::LINE)->pluck('statement_line_id')->all());

            foreach ($items->where('role', Item::PAYMENT) as $item) {
                if ($this->ledger->isClaimed((string) $item->payment_id)) {
                    throw new DomainException(__('That payment is already matched to another statement line.'));
                }
            }

            if ($items->where('role', Item::RECEIVABLE)->isNotEmpty()) {
                $customer = $this->billing->organization((string) $locked->org_id) ?? throw new DomainException(__('The customer no longer exists.'));

                foreach ($this->recorder->record($locked, $customer, $lines, $items->where('role', Item::RECEIVABLE), $actorId) as $lineId => $payment) {
                    $this->item($locked, Item::CREATED, (string) $payment->total, lineId: $lineId, paymentId: (string) $payment->id);
                }
            }

            $locked->forceFill(['status' => MatchStatus::Confirmed, 'confirmed_at' => now(), 'confirmed_by' => $actorId, 'auto_confirmed' => $auto])->save();
            $this->settleLines($lines, $locked);
            $this->log($locked, $auto ? 'finance.reconciliation.auto_confirmed' : 'finance.reconciliation.confirmed');

            return $locked;
        });
    }

    /** @throws DomainException when it is not waiting */
    public function reject(ReconciliationMatch $match, ?string $actorId): ReconciliationMatch
    {
        return DB::transaction(function () use ($match, $actorId): ReconciliationMatch {
            $locked = $this->lock($match);

            if ($locked->status !== MatchStatus::Suggested) {
                throw new DomainException(__('This match is no longer waiting to be confirmed.'));
            }

            $locked->forceFill(['status' => MatchStatus::Rejected, 'rejected_at' => now(), 'rejected_by' => $actorId])->save();
            $this->refreshLines($this->lineIds($locked));
            $this->log($locked, 'finance.reconciliation.rejected');

            return $locked;
        });
    }

    /**
     * Confirm a suggestion with other amounts: how much of the line goes to
     * each of the customer's invoices. The suggestion is superseded by the
     * admin's own match.
     *
     * @param  array<string, string>  $amounts  receivable id => amount
     *
     * @throws DomainException
     */
    public function split(ReconciliationMatch $match, array $amounts, ?string $actorId): ReconciliationMatch
    {
        return DB::transaction(function () use ($match, $amounts, $actorId): ReconciliationMatch {
            $locked = $this->lock($match);

            if ($locked->status !== MatchStatus::Suggested) {
                throw new DomainException(__('This match is no longer waiting to be confirmed.'));
            }

            $locked->forceFill(['status' => MatchStatus::Superseded])->save();
            $this->log($locked, 'finance.reconciliation.superseded');

            return $this->manual($this->lineIds($locked), (string) $locked->org_id, $amounts, [], $actorId);
        });
    }

    /**
     * An admin's own match: lines of money in for one customer's invoices,
     * so much to each, or for payments of its already recorded.
     *
     * @param  list<string>  $lineIds
     * @param  array<string, string>  $receivables  receivable id => amount
     * @param  list<string>  $payments  payment ids
     *
     * @throws DomainException saying what does not fit
     */
    public function manual(array $lineIds, string $organizationId, array $receivables, array $payments, ?string $actorId, ?string $fee = null): ReconciliationMatch
    {
        return DB::transaction(fn (): ReconciliationMatch => $this->makeManual($lineIds, $organizationId, $receivables, $payments, $actorId, $fee));
    }

    /**
     * @param  list<string>  $lineIds
     * @param  array<string, string>  $receivables
     * @param  list<string>  $payments
     *
     * @throws DomainException
     */
    private function makeManual(array $lineIds, string $organizationId, array $receivables, array $payments, ?string $actorId, ?string $fee): ReconciliationMatch
    {
        $lines = $this->openLines($lineIds);
        $currency = (string) $lines->first()->currency;
        $targets = [];
        $owed = '0.00';

        foreach (array_filter($receivables, fn ($amount): bool => $amount !== null && $amount !== '' && (float) $amount > 0) as $id => $amount) {
            $receivable = $this->receivables->find($organizationId, (string) $id) ?? throw new DomainException(__('That invoice is not this customer\'s.'));

            if ($receivable->currency !== $currency || ! $receivable->status->outstanding()) {
                throw new DomainException(__('Invoice :number is not owed in :currency.', ['number' => $this->receivables->number($receivable), 'currency' => $currency]));
            }

            $targets[(string) $receivable->id] = number_format((float) $amount, 2, '.', '');
            $owed = bcadd($owed, $receivable->balance(), 2);
        }

        $claimed = [];

        foreach ($payments as $id) {
            $payment = $this->billing->payment($organizationId, (string) $id);

            if ($payment === null || $payment->currency !== $currency || $this->ledger->isClaimed((string) $payment->id)) {
                throw new DomainException(__('That payment is not this customer\'s to match, or is already matched.'));
            }

            $claimed[(string) $payment->id] = (string) $payment->total;
            $owed = bcadd($owed, (string) $payment->total, 2);
        }

        if ($targets === [] && $claimed === []) {
            throw new DomainException(__('Choose an invoice or a payment to match the line to.'));
        }

        if ($fee !== null && $fee !== '') {
            if (! is_numeric($fee) || (float) $fee < 0 || $lines->count() !== 1 || $claimed !== []) {
                throw new DomainException(__('A bank fee is taken from one line paying invoices, and is not below zero.'));
            }

            $fee = number_format((float) $fee, 2, '.', '');
        } else {
            $fee = null;
        }

        $match = $this->suggest(new Candidate($organizationId, $currency, MatchRule::Manual, 100, ['manual'],
            $lines->mapWithKeys(fn (StatementLine $line): array => [(string) $line->id => (string) $line->amount])->all(), $targets, $claimed, $fee, $owed), $actorId);

        return $this->confirm($match, $actorId, checked: true);
    }

    /**
     * Undo a confirmed match: the payments it recorded are reversed, those it
     * only claimed are freed, and its lines are unmatched again.
     *
     * @throws DomainException when it is not confirmed, or a payment cannot be reversed
     */
    public function unmatch(ReconciliationMatch $match, string $reason, ?string $actorId): ReconciliationMatch
    {
        return DB::transaction(function () use ($match, $reason, $actorId): ReconciliationMatch {
            $locked = $this->lock($match);

            if ($locked->status !== MatchStatus::Confirmed) {
                throw new DomainException(__('Only a confirmed match can be undone.'));
            }

            foreach ($locked->items()->where('role', Item::CREATED)->get() as $item) {
                $payment = $this->billing->payment((string) $locked->org_id, (string) $item->payment_id);

                if ($payment !== null && $payment->reversed_at === null) {
                    $this->payments->reverse($payment, $reason, $actorId);
                }
            }

            $locked->forceFill(['status' => MatchStatus::Reversed, 'reversed_at' => now(), 'reversed_by' => $actorId, 'reversal_reason' => mb_substr($reason, 0, 500)])->save();
            $this->refreshLines($this->lineIds($locked));
            $this->log($locked, 'finance.reconciliation.reversed', ['reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Set a line aside: not a customer's payment (a transfer between the
     * platform's own accounts, a refund from a supplier).
     *
     * @throws DomainException when it is matched
     */
    public function ignore(StatementLine $line, string $reason, ?string $actorId): StatementLine
    {
        return DB::transaction(function () use ($line, $reason, $actorId): StatementLine {
            $locked = StatementLine::query()->lockForUpdate()->findOrFail($line->id);

            if ($locked->status === LineStatus::Matched) {
                throw new DomainException(__('A matched line is undone before it is set aside.'));
            }

            $this->supersede([(string) $locked->id]);
            $locked->forceFill(['status' => LineStatus::Ignored, 'ignored_at' => now(), 'ignored_by' => $actorId, 'ignored_reason' => mb_substr($reason, 0, 500)])->save();
            $this->audit->logChange('finance.reconciliation.line_ignored', 'StatementLine', (string) $locked->id, null, ['reason' => $reason], userId: $actorId);

            return $locked;
        });
    }

    /** Bring a line set aside back to be matched. */
    public function restore(StatementLine $line, ?string $actorId): StatementLine
    {
        if ($line->status !== LineStatus::Ignored) {
            return $line;
        }

        $line->forceFill(['status' => LineStatus::Unmatched, 'ignored_at' => null, 'ignored_by' => null, 'ignored_reason' => null])->save();
        $this->audit->logChange('finance.reconciliation.line_restored', 'StatementLine', (string) $line->id, null, null, userId: $actorId);

        return $line;
    }

    /**
     * What an admin rejected for the line, so it is not suggested again.
     *
     * @return list<string> Candidate::signature()s
     */
    public function rejectedSignatures(StatementLine $line): array
    {
        return ReconciliationMatch::withoutTenantScope(fn () => ReconciliationMatch::query()
            ->with('items')
            ->where('status', MatchStatus::Rejected->value)
            ->whereHas('items', fn ($query) => $query->where('statement_line_id', $line->id))
            ->get())
            ->map(fn (ReconciliationMatch $match): string => $this->signature($match))
            ->all();
    }

    /**
     * @param  list<string>  $lineIds
     * @return Collection<int, StatementLine>
     *
     * @throws DomainException
     */
    private function openLines(array $lineIds): Collection
    {
        $lines = StatementLine::query()->whereKey($lineIds)->lockForUpdate()->orderBy('booked_on')->orderBy('sequence')->get();

        if ($lines->count() !== count(array_unique($lineIds)) || $lines->isEmpty()) {
            throw new DomainException(__('Choose the statement lines to match.'));
        }

        foreach ($lines as $line) {
            if (! $line->isCredit() || in_array($line->status, [LineStatus::Matched, LineStatus::Ignored], true)) {
                throw new DomainException(__('A line is already matched or set aside.'));
            }
        }

        if ($lines->pluck('currency')->unique()->count() > 1) {
            throw new DomainException(__('Lines in different currencies are not matched together.'));
        }

        return $lines;
    }

    /**
     * @param  Collection<int, StatementLine>  $lines
     */
    private function settleLines(Collection $lines, ReconciliationMatch $match): void
    {
        StatementLine::query()->whereKey($lines->modelKeys())->update(['status' => LineStatus::Matched->value, 'updated_at' => now()]);
        $this->supersede($lines->modelKeys(), except: (string) $match->id);
    }

    /**
     * Every other suggestion of these lines is superseded.
     *
     * @param  list<string>  $lineIds
     */
    private function supersede(array $lineIds, ?string $except = null): void
    {
        $others = ReconciliationMatch::withoutTenantScope(fn () => ReconciliationMatch::query()
            ->where('status', MatchStatus::Suggested->value)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->whereHas('items', fn ($query) => $query->whereIn('statement_line_id', $lineIds))
            ->get());

        foreach ($others as $other) {
            $other->forceFill(['status' => MatchStatus::Superseded])->save();
            $this->refreshLines(array_diff($this->lineIds($other), $lineIds));
        }
    }

    /**
     * Each line's status from its matches: matched by a confirmed one, set
     * aside, suggested by one waiting, or unmatched.
     *
     * @param  list<string>  $lineIds
     */
    private function refreshLines(array $lineIds): void
    {
        foreach (StatementLine::query()->whereKey($lineIds)->get() as $line) {
            $statuses = ReconciliationMatch::withoutTenantScope(fn () => ReconciliationMatch::query()
                ->whereHas('items', fn ($query) => $query->where('statement_line_id', $line->id))
                ->pluck('status'))
                ->map(fn ($status): string => $status instanceof MatchStatus ? $status->value : (string) $status);

            $line->status = match (true) {
                $statuses->contains(MatchStatus::Confirmed->value) => LineStatus::Matched,
                $line->ignored_at !== null => LineStatus::Ignored,
                $statuses->contains(MatchStatus::Suggested->value) => LineStatus::Suggested,
                default => LineStatus::Unmatched,
            };
            $line->save();
        }
    }

    /** @return list<string> */
    private function lineIds(ReconciliationMatch $match): array
    {
        return $match->items()->where('role', Item::LINE)->pluck('statement_line_id')->map(fn ($id): string => (string) $id)->all();
    }

    private function signature(ReconciliationMatch $match): string
    {
        $prefix = [Item::LINE => 'l:', Item::RECEIVABLE => 'r:', Item::PAYMENT => 'p:'];
        $keys = $match->items
            ->filter(fn (Item $item): bool => isset($prefix[$item->role]))
            ->map(fn (Item $item): string => $prefix[$item->role].($item->statement_line_id ?? $item->receivable_id ?? $item->payment_id))
            ->sort()
            ->values()
            ->all();

        return implode(',', $keys);
    }

    private function lock(ReconciliationMatch $match): ReconciliationMatch
    {
        return ReconciliationMatch::withoutTenantScope(fn () => ReconciliationMatch::query()->lockForUpdate()->findOrFail($match->id));
    }

    private function item(ReconciliationMatch $match, string $role, string $amount, ?string $lineId = null, ?string $receivableId = null, ?string $paymentId = null): void
    {
        Item::query()->create([
            'match_id' => $match->id,
            'role' => $role,
            'statement_line_id' => $lineId,
            'receivable_id' => $receivableId,
            'payment_id' => $paymentId,
            'amount' => $amount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(ReconciliationMatch $match, string $action, array $extra = []): void
    {
        $this->audit->logFor((string) $match->org_id, $action, $match, [
            'rule' => $match->rule->value,
            'confidence' => $match->confidence,
            'currency' => $match->currency,
            'lines_amount' => (string) $match->lines_amount,
            'targets_amount' => (string) $match->targets_amount,
            'difference' => (string) $match->difference,
            ...$extra,
        ]);
    }
}
