<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Services;

use App\Domains\Billing\Enums\PaymentChannel;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Reconciliation\DTOs\Candidate;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\MatchRule;
use App\Domains\Billing\Reconciliation\Models\StatementImport;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Settings\FinanceSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Finds what a statement line of money in is, strongest evidence first:
 *
 *  1. A reference the payer or the gateway gave: the gateway's transaction
 *     id of a card payment recorded, a recorded payment's reference, or an
 *     invoice's number in the line's text.
 *  2. The amount, within Finance › Settings' tolerance, on a date within
 *     its window: of a payment recorded, or of what an invoice still owes.
 *  3. Several of one customer's invoices adding up to the line, several
 *     lines adding up to one invoice, or a part payment from a payer the
 *     line names.
 *
 * Each suggestion has a confidence from 0 to 100. Only one at HIGH or
 * above - the reference and the amount both exact, and nothing else as
 * strong - is confirmed without an admin; everything else waits for one
 * (Reconciliation). What an admin rejected for a line is not suggested
 * for it again.
 */
class Matcher
{
    /** At or above this a suggestion may be confirmed automatically. */
    public const HIGH = 95;

    /** How many suggestions a line keeps. */
    private const KEEP = 3;

    /** How many of one customer's invoices are tried in combination. */
    private const COMBINE_INVOICES = 10;

    /** How many nearby lines are tried in combination. */
    private const COMBINE_LINES = 30;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Reconciliation $reconciliation,
        private readonly FinanceSettings $settings,
    ) {}

    /**
     * Suggest for every line of money in still unmatched, in order.
     *
     * @param  iterable<StatementLine>  $lines
     * @return int how many lines have a suggestion or were matched
     */
    public function suggestFor(iterable $lines): int
    {
        $found = 0;

        foreach ($lines as $line) {
            $found += $this->suggest($line) ? 1 : 0;
        }

        return $found;
    }

    /** Suggest for one line; true when it now has a suggestion or a match. */
    public function suggest(StatementLine $line): bool
    {
        $line->refresh();

        if (! $line->isCredit() || $line->status !== LineStatus::Unmatched) {
            return false;
        }

        $rejected = $this->reconciliation->rejectedSignatures($line);
        $candidates = collect($this->candidates($line))
            ->reject(fn (Candidate $candidate): bool => in_array($candidate->signature(), $rejected, true))
            ->unique(fn (Candidate $candidate): string => $candidate->signature())
            ->sortByDesc('confidence')
            ->values()
            ->take(self::KEEP);

        if ($candidates->isEmpty()) {
            return false;
        }

        $best = $candidates->first();
        $runnerUp = $candidates->get(1);
        $matches = $candidates->map(fn (Candidate $candidate) => $this->reconciliation->suggest($candidate));

        // Only the one clear answer goes through by itself.
        // One that cannot go through - the bank transfers switched off, an
        // invoice paid meanwhile - waits as a suggestion, saying why when an
        // admin confirms it.
        if ($best->confidence >= self::HIGH && ($runnerUp === null || $runnerUp->confidence < self::HIGH)) {
            try {
                $this->reconciliation->confirm($matches->first(), null, auto: true);
            } catch (DomainException) {
            }
        }

        return true;
    }

    /**
     * @return list<Candidate>
     */
    private function candidates(StatementLine $line): array
    {
        $tolerance = max(0.0, (float) $this->settings->tolerance());
        $window = max(0, $this->settings->windowDays());
        $text = mb_strtoupper($line->searchText());
        $gateway = $line->source === StatementImport::GATEWAY;
        $payments = $this->ledger->unclaimed((string) $line->currency, $gateway ? PaymentChannel::Gateway : PaymentChannel::Bank);
        $owed = $this->ledger->owed((string) $line->currency);

        $byReference = [...$this->byGatewayId($line, $payments), ...$this->byPaymentReference($line, $payments, $text, $tolerance)];

        // A payment already recorded is what the line is; nothing new is
        // suggested beside it.
        if (collect($byReference)->contains(fn (Candidate $candidate): bool => $candidate->confidence >= 90)) {
            return $byReference;
        }

        return [
            ...$byReference,
            ...$this->byInvoiceNumber($line, $owed, $text, $tolerance),
            ...$this->byPaymentAmount($line, $payments, $tolerance, $window),
            ...$this->byOwedAmount($line, $owed, $text, $tolerance, $window),
            ...$this->bySeveralInvoices($line, $owed, $text, $tolerance),
            ...$this->bySeveralLines($line, $owed, $tolerance, $window),
            ...$this->byPayerName($line, $owed, $text),
        ];
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return list<Candidate>
     */
    private function byGatewayId(StatementLine $line, Collection $payments): array
    {
        if ($line->gateway_reference === null) {
            return [];
        }

        $payment = $payments->first(fn (Payment $payment): bool => $payment->gateway_reference === $line->gateway_reference);

        if ($payment === null) {
            return [];
        }

        $exact = bccomp((string) $payment->total, (string) $line->amount, 2) === 0;

        return [$this->toPayment($line, $payment, $exact ? 100 : 80, ['gateway_id', $exact ? 'amount_exact' : 'amount_differs'])];
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return list<Candidate>
     */
    private function byPaymentReference(StatementLine $line, Collection $payments, string $text, float $tolerance): array
    {
        $found = [];

        foreach ($payments as $payment) {
            $reference = trim((string) $payment->reference);

            if (mb_strlen($reference) < 4 || ! $this->mentions($text, $reference)) {
                continue;
            }

            $gap = abs((float) $payment->total - (float) $line->amount);
            $netGap = abs((float) $payment->total - (float) $payment->fee_amount - (float) $line->amount);
            [$confidence, $basis] = match (true) {
                $gap < 0.005 || ($payment->fee_amount !== null && $netGap < 0.005) => [100, 'amount_exact'],
                $gap <= $tolerance => [90, 'amount_within_tolerance'],
                default => [70, 'amount_differs'],
            };
            $found[] = $this->toPayment($line, $payment, $confidence, ['payment_reference', $basis]);
        }

        return $found;
    }

    /**
     * Invoice numbers the line names: one customer's at a time.
     *
     * @param  Collection<int, array{receivable: mixed, number: string, balance: string, names: list<string>}>  $owed
     * @return list<Candidate>
     */
    private function byInvoiceNumber(StatementLine $line, Collection $owed, string $text, float $tolerance): array
    {
        $named = $owed->filter(fn (array $row): bool => $row['number'] !== '' && $this->mentions($text, $row['number']));
        $found = [];

        foreach ($named->groupBy(fn (array $row): string => (string) $row['receivable']->org_id) as $organizationId => $rows) {
            $balance = (float) Candidate::sum($rows->pluck('balance')->all());
            $gap = (float) $line->amount - $balance;
            $several = $rows->count() > 1;

            [$confidence, $rule, $basis] = match (true) {
                abs($gap) < 0.005 => [$several ? 90 : 100, $several ? MatchRule::Combined : MatchRule::Reference, 'amount_exact'],
                abs($gap) <= $tolerance => [90, $several ? MatchRule::Combined : MatchRule::Reference, 'amount_within_tolerance'],
                default => [75, MatchRule::Partial, $gap < 0 ? 'short' : 'over'],
            };

            $found[] = $this->toInvoices($line, (string) $organizationId, $rows, $confidence, $rule,
                [$several ? 'invoice_numbers' : 'invoice_number', $basis], $tolerance);
        }

        return $found;
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return list<Candidate>
     */
    private function byPaymentAmount(StatementLine $line, Collection $payments, float $tolerance, int $window): array
    {
        $near = $payments->filter(fn (Payment $payment): bool => abs((float) $payment->total - (float) $line->amount) <= $tolerance
            && $this->within($line->booked_on, $payment->paid_at, $window))->values();

        return $near->map(function (Payment $payment) use ($line, $near): Candidate {
            $exact = abs((float) $payment->total - (float) $line->amount) < 0.005;
            $confidence = $near->count() > 1 ? 50 : ($exact ? 70 : 60);

            return $this->toPayment($line, $payment, $confidence, [$exact ? 'amount_exact' : 'amount_within_tolerance', 'date_window'], MatchRule::Amount);
        })->all();
    }

    /**
     * @param  Collection<int, array{receivable: mixed, number: string, balance: string, names: list<string>}>  $owed
     * @return list<Candidate>
     */
    private function byOwedAmount(StatementLine $line, Collection $owed, string $text, float $tolerance, int $window): array
    {
        $near = $owed->filter(fn (array $row): bool => abs((float) $row['balance'] - (float) $line->amount) <= $tolerance
            && $line->booked_on->greaterThanOrEqualTo(CarbonImmutable::parse($row['receivable']->issue_date->toDateString())->subDays($window)))->values();

        return $near->map(function (array $row) use ($line, $near, $text, $tolerance): Candidate {
            $exact = abs((float) $row['balance'] - (float) $line->amount) < 0.005;
            $named = $this->namesPayer($text, $row['names']);
            $confidence = ($near->count() > 1 ? 45 : ($exact ? 65 : 55)) + ($named ? 15 : 0);

            return $this->toInvoices($line, (string) $row['receivable']->org_id, collect([$row]), $confidence, MatchRule::Amount,
                array_values(array_filter([$exact ? 'amount_exact' : 'amount_within_tolerance', 'date_window', $named ? 'payer_name' : null])), $tolerance);
        })->all();
    }

    /**
     * Two to four of one customer's invoices that add up to the line.
     *
     * @param  Collection<int, array{receivable: mixed, number: string, balance: string, names: list<string>}>  $owed
     * @return list<Candidate>
     */
    private function bySeveralInvoices(StatementLine $line, Collection $owed, string $text, float $tolerance): array
    {
        $found = [];

        foreach ($owed->groupBy(fn (array $row): string => (string) $row['receivable']->org_id) as $organizationId => $rows) {
            $rows = $rows->take(self::COMBINE_INVOICES)->values();
            $subset = $this->subsetSum($rows->map(fn (array $row): float => (float) $row['balance'])->all(), (float) $line->amount, $tolerance, 4);

            if ($subset === null || count($subset) < 2) {
                continue;
            }

            $named = $this->namesPayer($text, $rows->first()['names']);
            $found[] = $this->toInvoices($line, (string) $organizationId, $rows->only($subset)->values(), 55 + ($named ? 15 : 0), MatchRule::Combined,
                array_values(array_filter(['amount_sum', $named ? 'payer_name' : null])), $tolerance);
        }

        return $found;
    }

    /**
     * This line and one or two others near it that add up to what one
     * invoice owes: a customer paying in parts.
     *
     * @param  Collection<int, array{receivable: mixed, number: string, balance: string, names: list<string>}>  $owed
     * @return list<Candidate>
     */
    private function bySeveralLines(StatementLine $line, Collection $owed, float $tolerance, int $window): array
    {
        $others = StatementLine::query()
            ->where('source', $line->source)
            ->where('currency', $line->currency)
            ->where('status', LineStatus::Unmatched->value)
            ->where('direction', $line->direction)
            ->whereKeyNot($line->id)
            ->whereBetween('booked_on', [$line->booked_on->copy()->subDays($window)->toDateString(), $line->booked_on->copy()->addDays($window)->toDateString()])
            ->orderBy('booked_on')
            ->limit(self::COMBINE_LINES)
            ->get();

        if ($others->isEmpty()) {
            return [];
        }

        $found = [];

        foreach ($owed as $row) {
            $rest = (float) $row['balance'] - (float) $line->amount;

            if ($rest <= 0) {
                continue;
            }

            $subset = $this->subsetSum($others->map(fn (StatementLine $other): float => (float) $other->amount)->all(), $rest, $tolerance, 2);

            if ($subset === null) {
                continue;
            }

            $lines = [(string) $line->id => (string) $line->amount];

            foreach ($subset as $key) {
                $lines[(string) $others[$key]->id] = (string) $others[$key]->amount;
            }

            $found[] = new Candidate((string) $row['receivable']->org_id, (string) $line->currency, MatchRule::Combined, 50, ['lines_sum'],
                $lines, [(string) $row['receivable']->id => $row['balance']], owed: $row['balance']);
        }

        return $found;
    }

    /**
     * A payer the line names, short of or over what its oldest invoice
     * owes: a part payment, for an admin to look at.
     *
     * @param  Collection<int, array{receivable: mixed, number: string, balance: string, names: list<string>}>  $owed
     * @return list<Candidate>
     */
    private function byPayerName(StatementLine $line, Collection $owed, string $text): array
    {
        $row = $owed->first(fn (array $row): bool => $this->namesPayer($text, $row['names']));

        if ($row === null) {
            return [];
        }

        return [$this->toInvoices($line, (string) $row['receivable']->org_id, collect([$row]), 40, MatchRule::Partial,
            ['payer_name', (float) $line->amount < (float) $row['balance'] ? 'short' : 'over'], 0.0)];
    }

    /**
     * @param  list<string>  $basis
     */
    private function toPayment(StatementLine $line, Payment $payment, int $confidence, array $basis, MatchRule $rule = MatchRule::Reference): Candidate
    {
        return new Candidate((string) $payment->org_id, (string) $line->currency, $rule, $confidence, $basis,
            [(string) $line->id => (string) $line->amount], payments: [(string) $payment->id => (string) $payment->total]);
    }

    /**
     * The line set against invoices of one customer, those due first first,
     * each up to what it owes; a shortfall no larger than the fee written
     * off (Finance › Settings) is the bank's or the gateway's fee.
     *
     * @param  Collection<int, array{receivable: mixed, number: string, balance: string, names: list<string>}>  $rows
     * @param  list<string>  $basis
     */
    private function toInvoices(StatementLine $line, string $organizationId, Collection $rows, int $confidence, MatchRule $rule, array $basis, float $tolerance): Candidate
    {
        $left = (string) $line->amount;
        $receivables = [];

        foreach ($rows as $row) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }

            $take = bccomp($left, $row['balance'], 2) < 0 ? $left : $row['balance'];
            $receivables[(string) $row['receivable']->id] = $take;
            $left = bcsub($left, $take, 2);
        }

        $owed = Candidate::sum($rows->pluck('balance')->all());
        $short = bcsub($owed, (string) $line->amount, 2);
        $fee = null;

        // Short by no more than the bank fee written off (and within the
        // tolerance): the bank or the gateway kept it, and the invoices are
        // paid in full. Short by more: a part payment, the rest still owed.
        $writeOff = min($tolerance, max(0.0, (float) $this->settings->feeWriteOff()));

        if (bccomp($short, '0', 2) > 0 && count($receivables) === $rows->count()) {
            if ((float) $short <= $writeOff + 0.0001) {
                $fee = $short;
                $receivables = $rows->mapWithKeys(fn (array $row): array => [(string) $row['receivable']->id => $row['balance']])->all();
            } elseif (! in_array('short', $basis, true)) {
                $basis[] = 'short';
            }
        }

        return new Candidate($organizationId, (string) $line->currency, $rule, min(100, $confidence), $basis,
            [(string) $line->id => (string) $line->amount], $receivables, fee: $fee, owed: $owed);
    }

    /**
     * The first set of at most $most values, smallest sets first, that adds
     * up to the target within the tolerance; null for none.
     *
     * @param  array<int, float>  $values
     * @return list<int>|null keys of $values
     */
    private function subsetSum(array $values, float $target, float $tolerance, int $most): ?array
    {
        $keys = array_keys($values);

        for ($size = 1; $size <= min($most, count($keys)); $size++) {
            foreach ($this->combinations($keys, $size) as $combination) {
                $sum = array_sum(array_map(fn (int $key): float => $values[$key], $combination));

                if (abs($sum - $target) <= max($tolerance, 0.004)) {
                    return $combination;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $keys
     * @return iterable<list<int>>
     */
    private function combinations(array $keys, int $size, int $start = 0): iterable
    {
        if ($size === 0) {
            yield [];

            return;
        }

        for ($i = $start; $i <= count($keys) - $size; $i++) {
            foreach ($this->combinations($keys, $size - 1, $i + 1) as $rest) {
                yield [$keys[$i], ...$rest];
            }
        }
    }

    /** Whether the text names a reference as a whole word: PLT-1 is not PLT-12. */
    private function mentions(string $text, string $reference): bool
    {
        $pattern = implode('[\s\-\/_.]?', array_map(fn (string $part): string => preg_quote($part, '/'), preg_split('/[\s\-\/_.]+/', mb_strtoupper(trim($reference))) ?: []));

        return $pattern !== '' && preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/u', $text) === 1;
    }

    /**
     * Whether the text names the customer, in either of its names.
     *
     * @param  list<string>  $names
     */
    private function namesPayer(string $text, array $names): bool
    {
        $haystack = $this->normalise($text);

        foreach ($names as $name) {
            $needle = $this->normalise($name);

            if (mb_strlen($needle) >= 4 && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtoupper($value)));
    }

    private function within(CarbonImmutable|\DateTimeInterface $a, CarbonImmutable|\DateTimeInterface $b, int $days): bool
    {
        return abs((int) CarbonImmutable::parse($a->format('Y-m-d'))->diffInDays(CarbonImmutable::parse($b->format('Y-m-d')), false)) <= $days;
    }
}
