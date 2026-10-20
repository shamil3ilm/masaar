<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\DTOs;

use App\Domains\Billing\Reconciliation\MatchRule;

/**
 * A match the matcher would suggest, or an admin makes: which lines, for
 * which of one customer's invoices or recorded payments, how much to each,
 * how sure, and why (basis codes: invoice_number, amount_exact ...).
 *
 * fee is the shortfall taken as the bank's fee, when the lines are short
 * of the invoices by no more than the tolerance.
 */
final readonly class Candidate
{
    /**
     * @param  list<string>  $basis
     * @param  array<string, string>  $lines  statement line id => amount
     * @param  array<string, string>  $receivables  receivable id => amount
     * @param  array<string, string>  $payments  payment id => amount
     */
    public function __construct(
        public string $organizationId,
        public string $currency,
        public MatchRule $rule,
        public int $confidence,
        public array $basis,
        public array $lines,
        public array $receivables = [],
        public array $payments = [],
        public ?string $fee = null,
        public ?string $owed = null,
    ) {}

    /**
     * The lines less what the invoices still owed, or the payments came to:
     * below zero short, above zero over.
     */
    public function difference(): string
    {
        return bcsub($this->linesAmount(), $this->owed ?? $this->targetsAmount(), 2);
    }

    /** What it joins, whatever the amounts: two candidates alike are one. */
    public function signature(): string
    {
        $keys = [...array_map(fn ($id): string => 'l:'.$id, array_keys($this->lines)),
            ...array_map(fn ($id): string => 'r:'.$id, array_keys($this->receivables)),
            ...array_map(fn ($id): string => 'p:'.$id, array_keys($this->payments))];
        sort($keys);

        return implode(',', $keys);
    }

    public function linesAmount(): string
    {
        return self::sum($this->lines);
    }

    public function targetsAmount(): string
    {
        return self::sum([...array_values($this->receivables), ...array_values($this->payments)]);
    }

    /** @param  array<array-key, string>  $amounts */
    public static function sum(array $amounts): string
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return $total;
    }
}
