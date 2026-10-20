<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use Illuminate\Validation\ValidationException;

/**
 * Amounts divided to the halala: the patient's share of an invoice an
 * insurer pays part of, and a part of an invoice spread over its lines.
 *
 * The patient's share is what the invoice states "for the account of" the
 * patient, beside the insurer's (ZATCA General VAT Guideline Healthcare,
 * January 2023, §5.2 p24 and Example 10 p23): amounts with VAT, of the one
 * invoice, which the two together come to exactly.
 *
 *   percent  that percentage of the invoice total, rounded half up.
 *   amount   a fixed amount, such as a deductible.
 *   lines    the lines marked as the patient's, with their VAT.
 */
final class ShareAllocation
{
    public const PATIENT = 'patient';

    public const INSURER = 'insurer';

    /**
     * The patient's share of the invoice, with VAT.
     *
     * @param  list<string>  $lineTotals  each line's total with its VAT
     * @param  list<string|null>  $payers  each line's payer, for a split by line
     *
     * @throws ValidationException when the patient's share is more than the invoice, or the insurer's is nothing
     */
    public static function patientShare(string $method, ?string $value, string $total, array $lineTotals = [], array $payers = []): string
    {
        $share = match ($method) {
            'percent' => self::roundHalfUp(bcdiv(bcmul($total, (string) $value, 6), '100', 6), 2),
            'amount' => bcadd((string) $value, '0', 2),
            default => self::linesOf($lineTotals, $payers),
        };

        if (bccomp($share, '0', 2) < 0 || bccomp($share, $total, 2) >= 0) {
            $field = match ($method) {
                'percent' => 'split.percent',
                'amount' => 'split.amount',
                default => 'split',
            };

            throw ValidationException::withMessages([$field => __('The patient\'s share is less than the invoice, so the insurer pays part of it.')]);
        }

        return $share;
    }

    /**
     * An amount over parts in proportion to them, the halalas left over to
     * the largest remainders, earliest first: shares that come to exactly
     * the amount.
     *
     * @param  list<string>  $parts
     * @return list<string>
     */
    public static function apportion(string $amount, array $parts): array
    {
        $cents = bcmul($amount, '100', 0);
        $weights = array_map(fn (string $part): string => bcmul($part, '100', 0), $parts);
        $whole = array_reduce($weights, fn (string $sum, string $weight): string => bcadd($sum, $weight, 0), '0');

        if (bccomp($whole, '0', 0) <= 0) {
            return array_map(fn (): string => '0.00', $parts);
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $exact = bcdiv(bcmul($cents, $weight, 0), $whole, 9);
            $shares[$index] = bcadd($exact, '0', 0);
            $remainders[$index] = bcsub($exact, $shares[$index], 9);
        }

        $left = (int) bcsub($cents, array_reduce($shares, fn (string $sum, string $share): string => bcadd($sum, $share, 0), '0'), 0);
        uksort($remainders, fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], 9) ?: $a <=> $b);

        foreach (array_slice(array_keys($remainders), 0, $left) as $index) {
            $shares[$index] = bcadd($shares[$index], '1', 0);
        }

        ksort($shares);

        return array_values(array_map(fn (string $share): string => bcdiv($share, '100', 2), $shares));
    }

    /**
     * @param  list<string>  $lineTotals
     * @param  list<string|null>  $payers
     */
    private static function linesOf(array $lineTotals, array $payers): string
    {
        $errors = [];
        $share = '0.00';

        foreach ($lineTotals as $index => $total) {
            $payer = $payers[$index] ?? null;

            if (! in_array($payer, [self::PATIENT, self::INSURER], true)) {
                $errors["lines.{$index}.payer"] = __('Line :line: say who pays it, the patient or the insurer.', ['line' => $index + 1]);
            } elseif ($payer === self::PATIENT) {
                $share = bcadd($share, $total, 2);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $share;
    }

    private static function roundHalfUp(string $value, int $scale): string
    {
        $factor = bcpow('10', (string) $scale);

        return bcdiv(bcadd(bcmul($value, $factor, 6), bccomp($value, '0', 6) >= 0 ? '0.5' : '-0.5', 0), $factor, $scale);
    }
}
