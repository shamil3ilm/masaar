<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use Illuminate\Validation\ValidationException;

/**
 * A sale's lines divided between the patient and the insurer.
 *
 * Each share lists the sale's own items at the share of their price that
 * its payer pays - what that payer's consideration is for - so the VAT of
 * each share is its own, line by line and category by category. Nothing is
 * shown as a discount: a discount is a price reduction (UNCL 5189 code 95),
 * and the other payer's share is not one.
 *
 * Amounts are whole halalas, and the two shares of a line always add up to
 * the line:
 *
 *   percent  each unit price is divided, the patient's share rounded half up
 *            and the insurer paying the rest of the unit.
 *   amount   the patient's fixed amount is spread over the lines in
 *            proportion to them, the halalas left over going to the largest
 *            remainders, so the shares come to exactly the amount. A line's
 *            share that does not divide by its quantity is stated as two
 *            prices a halala apart - two units at 1.67 and one at 1.66 for
 *            5.00 of three - and the insurer's as their complements.
 *   lines    each line goes whole to the payer it names.
 *
 * The prices are as entered: with VAT in them or not, as the sale says.
 */
final class ShareAllocation
{
    public const PATIENT = 'patient';

    public const INSURER = 'insurer';

    /**
     * @param  list<array<string, mixed>>  $lines  the sale's lines, validated
     * @return array{patient: list<array<string, mixed>>, insurer: list<array<string, mixed>>}
     *
     * @throws ValidationException when a share would be empty, or a line cannot be divided to the halala
     */
    public static function divide(array $lines, string $method, ?string $value): array
    {
        $lines = array_values($lines);
        self::refuseAdjustedLines($lines);

        $shares = match ($method) {
            'percent' => self::byPercent($lines, (string) $value),
            'amount' => self::byAmount($lines, (string) $value),
            default => self::byLine($lines),
        };

        if ($shares[self::PATIENT] === [] || $shares[self::INSURER] === []) {
            $field = match ($method) {
                'percent' => 'split.percent',
                'amount' => 'split.amount',
                default => 'split',
            };

            throw ValidationException::withMessages([$field => __('A split sale leaves the patient a share and the insurer a share: each pays some of it.')]);
        }

        return $shares;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{patient: list<array<string, mixed>>, insurer: list<array<string, mixed>>}
     */
    private static function byPercent(array $lines, string $percent): array
    {
        $shares = [self::PATIENT => [], self::INSURER => []];

        if (! is_numeric($percent) || bccomp($percent, '0', 2) <= 0 || bccomp($percent, '100', 2) >= 0) {
            return $shares;
        }

        foreach ($lines as $line) {
            $unit = self::cents($line['unit_price']);
            $patient = self::roundHalfUp(bcdiv(bcmul($unit, $percent, 6), '100', 6));
            $quantity = (string) $line['quantity'];

            self::add($shares, self::PATIENT, $line, [[$quantity, $patient]]);
            self::add($shares, self::INSURER, $line, [[$quantity, bcsub($unit, $patient, 0)]]);
        }

        return $shares;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{patient: list<array<string, mixed>>, insurer: list<array<string, mixed>>}
     */
    private static function byAmount(array $lines, string $amount): array
    {
        $shares = [self::PATIENT => [], self::INSURER => []];

        if (! is_numeric($amount)) {
            return $shares;
        }

        // Each line in thousandths of a halala, since a quantity has three places.
        $totals = array_map(fn (array $line): string => bcmul(bcmul((string) $line['quantity'], '1000', 0), self::cents($line['unit_price']), 0), $lines);
        $sale = array_reduce($totals, fn (string $sum, string $total): string => bcadd($sum, $total, 0), '0');
        $target = bcmul(self::cents($amount), '1000', 0);

        if (bccomp($target, '0', 0) <= 0 || bccomp($target, $sale, 0) >= 0) {
            return $shares;
        }

        foreach (self::apportion(self::cents($amount), $totals, $sale) as $index => $patient) {
            self::realise($shares, $index, $lines[$index], $patient);
        }

        return $shares;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{patient: list<array<string, mixed>>, insurer: list<array<string, mixed>>}
     */
    private static function byLine(array $lines): array
    {
        $shares = [self::PATIENT => [], self::INSURER => []];
        $errors = [];

        foreach ($lines as $index => $line) {
            $payer = $line['payer'] ?? null;

            if (! in_array($payer, [self::PATIENT, self::INSURER], true)) {
                $errors["lines.{$index}.payer"] = __('Line :line: say who pays it, the patient or the insurer.', ['line' => $index + 1]);

                continue;
            }

            $shares[$payer][] = self::without($line);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $shares;
    }

    /**
     * The amount's halalas over the lines in proportion to them, the
     * leftover halalas to the largest remainders, earliest line first.
     *
     * @param  list<string>  $totals  thousandths of a halala
     * @return list<string> halalas
     */
    private static function apportion(string $amount, array $totals, string $sale): array
    {
        $shares = [];
        $remainders = [];

        foreach ($totals as $index => $total) {
            $exact = bcdiv(bcmul($amount, $total, 0), $sale, 9);
            $shares[$index] = bcadd($exact, '0', 0);
            $remainders[$index] = bcsub($exact, $shares[$index], 9);
        }

        $left = (int) bcsub($amount, array_reduce($shares, fn (string $sum, string $share): string => bcadd($sum, $share, 0), '0'), 0);
        uksort($remainders, fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], 9) ?: $a <=> $b);

        foreach (array_slice(array_keys($remainders), 0, $left) as $index) {
            $shares[$index] = bcadd($shares[$index], '1', 0);
        }

        ksort($shares);

        return array_values($shares);
    }

    /**
     * A line's patient share, in halalas, as unit prices; the insurer's as
     * their complements.
     *
     * @param  array{patient: list<array<string, mixed>>, insurer: list<array<string, mixed>>}  $shares
     * @param  array<string, mixed>  $line
     */
    private static function realise(array &$shares, int $index, array $line, string $patient): void
    {
        $unit = self::cents($line['unit_price']);
        $thousandths = bcmul((string) $line['quantity'], '1000', 0);

        if (bcmod($thousandths, '1000') === '0') {
            $quantity = bcdiv($thousandths, '1000', 0);
            $base = bcdiv($patient, $quantity, 0);
            $over = bcmod($patient, $quantity);
            $rest = bcsub($quantity, $over, 0);

            self::add($shares, self::PATIENT, $line, [[$over, bcadd($base, '1', 0)], [$rest, $base]]);
            self::add($shares, self::INSURER, $line, [[$over, bcsub(bcsub($unit, $base, 0), '1', 0)], [$rest, bcsub($unit, $base, 0)]]);

            return;
        }

        // A part quantity, such as 0.5 kg, divides only where its share
        // comes to whole halalas a unit.
        $perUnit = bcdiv(bcmul($patient, '1000', 0), $thousandths, 6);

        if (bccomp($perUnit, bcadd($perUnit, '0', 0), 6) !== 0) {
            throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Line :line: its share of the amount does not divide evenly over a quantity of :quantity. Split by percentage or by line instead.', ['line' => $index + 1, 'quantity' => (string) $line['quantity']])]);
        }

        $quantity = (string) $line['quantity'];
        $perUnit = bcadd($perUnit, '0', 0);

        self::add($shares, self::PATIENT, $line, [[$quantity, $perUnit]]);
        self::add($shares, self::INSURER, $line, [[$quantity, bcsub($unit, $perUnit, 0)]]);
    }

    /**
     * The line once per piece with something in it: a quantity and a unit
     * price above nothing.
     *
     * @param  array{patient: list<array<string, mixed>>, insurer: list<array<string, mixed>>}  $shares
     * @param  array<string, mixed>  $line
     * @param  list<array{string, string}>  $pieces  quantity, unit price in halalas
     */
    private static function add(array &$shares, string $payer, array $line, array $pieces): void
    {
        foreach ($pieces as [$quantity, $cents]) {
            if (bccomp($quantity, '0', 3) > 0 && bccomp($cents, '0', 0) > 0) {
                $shares[$payer][] = [...self::without($line), 'quantity' => $quantity, 'unit_price' => bcdiv($cents, '100', 2)];
            }
        }
    }

    /**
     * A line as a share states it: without its payer, which is the split's
     * and not the document's.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function without(array $line): array
    {
        unset($line['payer']);

        return $line;
    }

    /**
     * A line's own discount or charges would have to be divided too, and
     * what each share then states would no longer be its price.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private static function refuseAdjustedLines(array $lines): void
    {
        $errors = [];

        foreach ($lines as $index => $line) {
            if (is_numeric($line['discount_amount'] ?? null) && bccomp((string) $line['discount_amount'], '0', 2) > 0) {
                $errors["lines.{$index}.discount_amount"] = __('Line :line: a split sale takes no line discount. Enter the price after it.', ['line' => $index + 1]);
            }

            if (! empty($line['charges'])) {
                $errors["lines.{$index}.charges"] = __('Line :line: a split sale takes no line charges. Enter the price with them.', ['line' => $index + 1]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function cents(mixed $amount): string
    {
        return self::roundHalfUp(bcmul((string) $amount, '100', 6));
    }

    private static function roundHalfUp(string $value): string
    {
        return bcadd($value, bccomp($value, '0', 6) >= 0 ? '0.5' : '-0.5', 0);
    }
}
