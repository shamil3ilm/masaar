<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;

/**
 * What an invoice's lines and discount add up to.
 *
 * A line's VAT is its net times its rate, rounded half up to the halalah. The
 * document's VAT is not the sum of those: it is each tax category's net, less
 * that category's share of the document discount, times the category rate.
 * ZATCA checks the document VAT against those category amounts (BR-CO-14),
 * and XmlBuilder declares them through categories(), so both read one rule.
 */
final readonly class InvoiceTotals
{
    /**
     * ZATCA's standard VAT rate, applied when a line does not state its own.
     */
    public const DEFAULT_TAX_RATE = '15';

    /**
     * @param  list<array{net: string, rate: string, tax: string, total: string}>  $lines
     */
    private function __construct(
        public array $lines,
        public string $subtotal,
        public string $discount,
        public string $tax,
        public string $total,
    ) {}

    /**
     * @param  array<array{quantity: int|float|string, unit_price: int|float|string, tax_rate?: int|float|string|null, tax_category?: string|null}>  $lines
     */
    public static function of(array $lines, string $discount = '0'): self
    {
        $priced = [];
        $subtotal = '0';
        $nets = [];
        $rates = [];

        foreach ($lines as $line) {
            $rate = (string) ($line['tax_rate'] ?? self::DEFAULT_TAX_RATE);
            $net = self::round(bcmul((string) $line['quantity'], (string) $line['unit_price'], 6));
            $tax = self::round(bcdiv(bcmul($net, $rate, 6), '100', 6));

            $priced[] = ['net' => $net, 'rate' => $rate, 'tax' => $tax, 'total' => bcadd($net, $tax, 2)];
            $subtotal = bcadd($subtotal, $net, 2);

            $key = ($line['tax_category'] ?? 'S').'_'.(float) $rate;
            $nets[$key] = ($nets[$key] ?? 0.0) + (float) $net;
            $rates[$key] = (float) $rate;
        }

        $discount = bcadd($discount, '0', 2);
        $tax = '0';

        foreach (self::categories($nets, $rates, (float) $discount) as $category) {
            $tax = bcadd($tax, number_format($category['tax'], 2, '.', ''), 2);
        }

        return new self($priced, $subtotal, $discount, $tax, bcadd(bcsub($subtotal, $discount, 2), $tax, 2));
    }

    /**
     * Each category's taxable base and VAT once the discount is shared out.
     *
     * @param  array<string, float>  $nets  net amount per category key
     * @param  array<string, float>  $rates  VAT rate per category key
     * @return array<string, array{share: float, base: float, tax: float}>
     */
    public static function categories(array $nets, array $rates, float $discount): array
    {
        $shares = FatooraConfig::apportionAllowance($nets, $discount);
        $categories = [];

        foreach ($nets as $key => $net) {
            $share = $shares[$key] ?? 0.0;
            $base = round($net - $share, 2);

            $categories[$key] = [
                'share' => $share,
                'base' => $base,
                'tax' => round($base * $rates[$key] / 100, 2),
            ];
        }

        return $categories;
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
