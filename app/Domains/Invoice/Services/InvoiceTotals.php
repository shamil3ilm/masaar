<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Support\AllowanceApportionment;

/**
 * What an invoice's lines, discounts and charges add up to.
 *
 * A line's VAT is its net times its rate, rounded half up to the halalah. The
 * document's VAT is not the sum of those: it is each tax category's net, less
 * that category's share of the document discount, plus the document charges
 * made at that category, times the category rate. ZATCA checks the document
 * VAT against those category amounts (BR-CO-14), and XmlBuilder declares them
 * through categories(), so both read one rule.
 *
 * A line's net is quantity times price, less its own discount, plus its own
 * charges (BT-131, as BR-KSA-EN16931-11 and IBR-147-AE check it). A charge
 * is an amount, or a percentage of a base - by default the line's quantity
 * times price, or for a document charge the lines' total - rounded half up
 * to the halalah, as ZATCA and PINT AE check amount = base x percentage / 100
 * (BR-KSA-EN16931-03, IBR-146-AE).
 *
 * Prices may also be entered VAT-inclusive, as a shelf price is. Then the
 * customer's figures are the fixed ones: each line's gross is exact, and so is
 * the document total. The net ZATCA is given is backed out of the gross per
 * tax category, and the document VAT is whatever the gross leaves over the
 * net - ZATCA's own method: "The VAT amount calculated as a fraction of
 * 15/115 of the VAT-inclusive price" (Guideline for Tax Invoicing and
 * Records, v3, 4.2.4, Example 6). Rounding the net and the VAT separately
 * cannot land on every gross - at 15% about one total in eight is out of
 * reach - so where it cannot, the halalah sits in a category's VAT. ZATCA's
 * check of a category's VAT against its base times its rate (BR-S-09,
 * BR-CO-17) allows a halalah per invoice line, so this does not register;
 * the totals rules it enforces as errors (BR-CO-14, BR-CO-15) hold exactly.
 * Discounts and charges on such an invoice are given with VAT in them too.
 */
final readonly class InvoiceTotals
{
    /**
     * ZATCA's standard VAT rate, applied when a line does not state its own.
     */
    public const DEFAULT_TAX_RATE = '15';

    /**
     * @param  list<array{net: string, unit_price: string, rate: string, tax: string, total: string, discount: string, charges: list<array{amount: string, percent: ?string, base: ?string}>}>  $lines
     * @param  list<array{amount: string, net: string, percent: ?string, base: ?string, category: string, rate: string, key: string}>  $charges  The document charges, amounts as entered.
     * @param  string  $chargeTotal  The document charges' net, together (BT-108).
     */
    private function __construct(
        public array $lines,
        public string $subtotal,
        public string $discount,
        public string $tax,
        public string $total,
        public array $charges = [],
        public string $chargeTotal = '0.00',
    ) {}

    /**
     * A stored net, when a line carries one, is used rather than recomputed.
     *
     * @param  array<array{quantity: int|float|string, unit_price: int|float|string, net?: string|null, discount_amount?: int|float|string|null, charges?: list<array<string, mixed>>, tax_rate?: int|float|string|null, tax_category?: string|null}>  $lines
     * @param  bool  $inclusive  The unit prices, the discounts and the charges include VAT.
     * @param  list<array{amount?: int|float|string|null, percent?: int|float|string|null, base_amount?: int|float|string|null, tax_category?: string|null, tax_rate?: int|float|string|null}>  $charges  The document charges.
     */
    public static function of(array $lines, string $discount = '0', bool $inclusive = false, array $charges = []): self
    {
        return $inclusive ? self::inclusive($lines, $discount, $charges) : self::exclusive($lines, $discount, $charges);
    }

    /**
     * A charge's amount: as given, or its percentage of its base - its own
     * base when it names one - to the halalah.
     *
     * @param  array<string, mixed>  $charge
     * @return array{amount: string, percent: ?string, base: ?string}
     */
    public static function chargeAmount(array $charge, string $defaultBase): array
    {
        $percent = $charge['percent'] ?? null;

        if ($percent === null || $percent === '') {
            return ['amount' => bcadd((string) ($charge['amount'] ?? '0'), '0', 2), 'percent' => null, 'base' => null];
        }

        $base = bcadd((string) ($charge['base_amount'] ?? $defaultBase), '0', 2);

        return [
            'amount' => self::round(bcdiv(bcmul($base, (string) $percent, 6), '100', 6)),
            'percent' => bcadd((string) $percent, '0', 2),
            'base' => $base,
        ];
    }

    /**
     * @param  array<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $charges
     */
    private static function exclusive(array $lines, string $discount, array $charges): self
    {
        $priced = [];
        $subtotal = '0';
        $nets = [];
        $rates = [];

        foreach ($lines as $line) {
            $rate = (string) ($line['tax_rate'] ?? self::DEFAULT_TAX_RATE);
            $lineDiscount = bcadd((string) ($line['discount_amount'] ?? '0'), '0', 2);
            $gross = self::round(bcmul((string) $line['quantity'], (string) $line['unit_price'], 6));
            $lineCharges = self::lineCharges($line, $gross);
            $net = isset($line['net'])
                ? bcadd((string) $line['net'], '0', 2)
                : bcadd(bcsub($gross, $lineDiscount, 2), self::sum($lineCharges), 2);
            $tax = self::round(bcdiv(bcmul($net, $rate, 6), '100', 6));

            $priced[] = [
                'net' => $net,
                'unit_price' => (string) $line['unit_price'],
                'rate' => $rate,
                'tax' => $tax,
                'total' => bcadd($net, $tax, 2),
                'discount' => $lineDiscount,
                'charges' => $lineCharges,
            ];
            $subtotal = bcadd($subtotal, $net, 2);

            $key = self::key($line, $rate);
            $nets[$key] = ($nets[$key] ?? 0.0) + (float) $net;
            $rates[$key] = (float) $rate;
        }

        $discount = bcadd($discount, '0', 2);
        $documentCharges = self::documentCharges($charges, $subtotal, false);
        $chargeTotal = self::sum($documentCharges, 'net');
        $tax = '0';

        foreach (self::categories($nets, $rates + self::chargeRates($documentCharges), (float) $discount, null, self::chargesByKey($documentCharges)) as $category) {
            $tax = bcadd($tax, number_format($category['tax'], 2, '.', ''), 2);
        }

        $total = bcadd(bcadd(bcsub($subtotal, $discount, 2), $chargeTotal, 2), $tax, 2);

        return new self($priced, $subtotal, $discount, $tax, $total, $documentCharges, $chargeTotal);
    }

    /**
     * Back the net out of gross prices, keeping every figure the customer
     * sees exact: each line's gross and the document total.
     *
     * @param  array<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $charges
     */
    private static function inclusive(array $lines, string $grossDiscount, array $charges): self
    {
        $lines = array_values($lines);
        $gross = [];
        $lineCharges = [];
        $byKey = [];
        $rates = [];

        foreach ($lines as $index => $line) {
            $rate = (string) ($line['tax_rate'] ?? self::DEFAULT_TAX_RATE);
            // What the customer pays for the line: its price, less its own
            // discount, plus its own charges, all with VAT in them.
            $priceGross = self::round(bcmul((string) $line['quantity'], (string) $line['unit_price'], 6));
            $lineCharges[$index] = self::lineCharges($line, $priceGross);
            $gross[$index] = bcadd(
                bcsub($priceGross, bcadd((string) ($line['discount_amount'] ?? '0'), '0', 2), 2),
                self::sum($lineCharges[$index]),
                2,
            );

            $key = self::key($line, $rate);
            $byKey[$key][] = $index;
            $rates[$key] = $rate;
        }

        // Each category's net is decided on its gross and then shared among
        // its lines, so the rounding happens once per category instead of
        // once per line and drifting with the number of lines.
        $nets = [];
        $grossByKey = [];

        foreach ($byKey as $key => $indexes) {
            $categoryGross = '0';
            $largest = $indexes[0];
            $shared = '0';

            foreach ($indexes as $i) {
                $categoryGross = bcadd($categoryGross, $gross[$i], 2);
                $nets[$i] = self::netOf($gross[$i], $rates[$key]);
                $shared = bcadd($shared, $nets[$i], 2);

                if (bccomp($gross[$i], $gross[$largest], 2) > 0) {
                    $largest = $i;
                }
            }

            $grossByKey[$key] = (float) $categoryGross;
            $nets[$largest] = bcadd($nets[$largest], bcsub(self::netOf($categoryGross, $rates[$key]), $shared, 2), 2);
        }

        $priced = [];
        $subtotal = '0';
        $grossTotal = '0';

        foreach ($lines as $index => $line) {
            $quantity = (string) $line['quantity'];

            $priced[] = [
                'net' => $nets[$index],
                // The stored price stays a net price, as everything that
                // reads it assumes. It is rounded; 'net' is the exact figure.
                'unit_price' => bccomp($quantity, '0', 3) > 0
                    ? self::round(bcdiv($nets[$index], $quantity, 6))
                    : '0.00',
                'rate' => (string) ($line['tax_rate'] ?? self::DEFAULT_TAX_RATE),
                'tax' => bcsub($gross[$index], $nets[$index], 2),
                'total' => $gross[$index],
                // As entered, with VAT in them: the net already reflects both.
                'discount' => bcadd((string) ($line['discount_amount'] ?? '0'), '0', 2),
                'charges' => $lineCharges[$index],
            ];
            $subtotal = bcadd($subtotal, $nets[$index], 2);
            $grossTotal = bcadd($grossTotal, $gross[$index], 2);
        }

        // The discount was given gross. Each category's share of it comes
        // off at that category's rate, and the customer pays the gross less
        // the gross discount, exactly.
        $grossDiscount = bcadd($grossDiscount, '0', 2);
        $discount = '0';

        foreach (AllowanceApportionment::apportion($grossByKey, (float) $grossDiscount) as $key => $share) {
            $discount = bcadd($discount, self::netOf(number_format($share, 2, '.', ''), $rates[$key]), 2);
        }

        // So were the charges, and they are paid exactly: each one's net is
        // backed out at its own rate.
        $documentCharges = self::documentCharges($charges, $grossTotal, true);
        $chargeTotal = self::sum($documentCharges, 'net');
        $total = bcadd(bcsub($grossTotal, $grossDiscount, 2), self::sum($documentCharges), 2);

        return new self(
            $priced,
            $subtotal,
            $discount,
            bcsub($total, bcadd(bcsub($subtotal, $discount, 2), $chargeTotal, 2), 2),
            $total,
            $documentCharges,
            $chargeTotal,
        );
    }

    /**
     * Each category's taxable base and VAT once the discount is shared out
     * and the document charges are added to the categories they are made at.
     *
     * @param  array<string, float>  $nets  net amount per category key
     * @param  array<string, float>  $rates  VAT rate per category key, for every
     *                                       key in $nets and $charges
     * @param  float|null  $tax  The document's VAT when it is fixed rather than
     *                           derived - an invoice priced VAT-inclusive. The
     *                           halalah between it and the categories' own
     *                           rounding is carried by the largest category,
     *                           so the categories still add up to it (BR-CO-14).
     * @param  array<string, float>  $charges  net document charges per category key;
     *                                         a key no line has is a category of its own
     * @return array<string, array{share: float, base: float, tax: float}>
     */
    public static function categories(array $nets, array $rates, float $discount, ?float $tax = null, array $charges = []): array
    {
        // The discount is shared among the lines' categories only: a
        // category of charges alone was not discounted.
        $shares = AllowanceApportionment::apportion($nets, $discount);
        $categories = [];
        $largest = null;

        foreach (array_keys($nets + $charges) as $key) {
            $share = $shares[$key] ?? 0.0;
            $base = round(($nets[$key] ?? 0.0) - $share + ($charges[$key] ?? 0.0), 2);

            $categories[$key] = [
                'share' => $share,
                'base' => $base,
                // In bcmath, half up, as line VAT is: in floating point
                // 4.10 * 15 / 100 is 0.61499..., which rounded to 0.61
                // against a line VAT of 0.62.
                'tax' => (float) self::round(bcdiv(bcmul(number_format($base, 2, '.', ''), (string) $rates[$key], 6), '100', 6)),
            ];

            if ($largest === null || $base > $categories[$largest]['base']) {
                $largest = $key;
            }
        }

        if ($tax !== null && $largest !== null) {
            $residual = round($tax - array_sum(array_column($categories, 'tax')), 2);
            $categories[$largest]['tax'] = round($categories[$largest]['tax'] + $residual, 2);
        }

        return $categories;
    }

    /**
     * The category key a line, or a charge, is taxed under.
     *
     * @param  array<string, mixed>  $line
     */
    public static function key(array $line, string $rate): string
    {
        return ($line['tax_category'] ?? 'S').'_'.(float) $rate;
    }

    /**
     * The net in a gross amount at a rate, to the halalah.
     */
    public static function netOf(string $gross, string $rate): string
    {
        return self::round(bcdiv(bcmul($gross, '100', 6), bcadd('100', $rate, 6), 6));
    }

    /**
     * A line's charges, each an amount as entered: given, or a percentage of
     * the line's quantity times price unless it names its own base.
     *
     * @param  array<string, mixed>  $line
     * @return list<array{amount: string, percent: ?string, base: ?string}>
     */
    private static function lineCharges(array $line, string $gross): array
    {
        return array_values(array_map(
            fn (array $charge): array => self::chargeAmount($charge, $gross),
            (array) ($line['charges'] ?? []),
        ));
    }

    /**
     * The document charges, each at its own category and rate, with its
     * net: the amount itself, or on an invoice priced with VAT in it, the
     * net backed out of it at its rate.
     *
     * @param  list<array<string, mixed>>  $charges
     * @return list<array{amount: string, net: string, percent: ?string, base: ?string, category: string, rate: string, key: string}>
     */
    private static function documentCharges(array $charges, string $defaultBase, bool $inclusive): array
    {
        $resolved = [];

        foreach ($charges as $charge) {
            $rate = bcadd((string) ($charge['tax_rate'] ?? self::DEFAULT_TAX_RATE), '0', 2);
            $category = (string) ($charge['tax_category'] ?? 'S');
            $amount = self::chargeAmount($charge, $defaultBase);

            $resolved[] = [
                ...$amount,
                'net' => $inclusive ? self::netOf($amount['amount'], $rate) : $amount['amount'],
                'category' => $category,
                'rate' => $rate,
                'key' => self::key(['tax_category' => $category], $rate),
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array{net: string, key: string}>  $charges
     * @return array<string, float>
     */
    private static function chargesByKey(array $charges): array
    {
        $byKey = [];

        foreach ($charges as $charge) {
            $byKey[$charge['key']] = ($byKey[$charge['key']] ?? 0.0) + (float) $charge['net'];
        }

        return $byKey;
    }

    /**
     * @param  list<array{rate: string, key: string}>  $charges
     * @return array<string, float>
     */
    private static function chargeRates(array $charges): array
    {
        return array_column(array_map(fn (array $c): array => ['key' => $c['key'], 'rate' => (float) $c['rate']], $charges), 'rate', 'key');
    }

    /**
     * @param  list<array<string, string|null>>  $items
     */
    private static function sum(array $items, string $field = 'amount'): string
    {
        return array_reduce($items, fn (string $sum, array $item): string => bcadd($sum, (string) $item[$field], 2), '0.00');
    }

    /**
     * Round half away from zero. bcmath truncates, which understates VAT.
     */
    private static function round(string $value, int $scale = 2): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, str_starts_with($value, '-') ? "-$half" : $half, $scale);
    }
}
