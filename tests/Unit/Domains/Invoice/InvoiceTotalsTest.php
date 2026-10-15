<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Invoice;

use App\Domains\Invoice\Services\InvoiceTotals;
use PHPUnit\Framework\TestCase;

/**
 * Exclusive and inclusive prices, and the figures each keeps exact.
 *
 * Exclusive prices fix the net and derive the VAT. Inclusive prices fix what
 * the customer pays - each line's gross and the total - and back the net out
 * of it, which every gross figure here is checked against.
 */
class InvoiceTotalsTest extends TestCase
{
    public function test_exclusive_prices_add_vat(): void
    {
        $totals = InvoiceTotals::of([$this->line('1', '100.00')]);

        $this->assertSame(['100.00', '0.00', '15.00', '115.00'], $this->figures($totals));
    }

    public function test_inclusive_prices_contain_vat(): void
    {
        $totals = InvoiceTotals::of([$this->line('1', '115.00')], inclusive: true);

        $this->assertSame(['100.00', '0.00', '15.00', '115.00'], $this->figures($totals));
        $this->assertSame('115.00', $totals->lines[0]['total']);
    }

    /**
     * Three at 10.00 is 30.00 to the customer. 30 / 1.15 is 26.087, and a net
     * of 26.09 with its own VAT rounded comes to 30.00 - but a net unit price
     * of 8.70 times three would not; the exact net is carried separately.
     */
    public function test_the_customer_total_is_exact(): void
    {
        $totals = InvoiceTotals::of([$this->line('3', '10.00')], inclusive: true);

        $this->assertSame('30.00', $totals->total);
        $this->assertSame('30.00', bcadd($totals->lines[0]['net'], $totals->lines[0]['tax'], 2));
        $this->assertSame('30.00', bcadd(bcsub($totals->subtotal, $totals->discount, 2), $totals->tax, 2));
    }

    /**
     * Every gross from 0.01 to 30.00, alone and alongside other lines: the
     * customer pays exactly the gross, every line's net and VAT add up to its
     * gross, and the categories add up to the document's VAT.
     */
    public function test_every_gross_is_kept(): void
    {
        for ($cents = 1; $cents <= 3000; $cents += 7) {
            $price = number_format($cents / 100, 2, '.', '');
            $totals = InvoiceTotals::of([
                $this->line('1', $price),
                $this->line('2', '4.99'),
                $this->line('1', '0.99'),
            ], inclusive: true);

            $expected = bcadd(bcadd($price, '9.98', 2), '0.99', 2);
            $this->assertSame($expected, $totals->total, "gross {$price}");

            foreach ($totals->lines as $line) {
                $this->assertSame($line['total'], bcadd($line['net'], $line['tax'], 2));
            }

            $categories = InvoiceTotals::categories(['S_15' => (float) $totals->subtotal], ['S_15' => 15.0], 0.0, (float) $totals->tax);
            $this->assertSame((float) $totals->tax, $categories['S_15']['tax']);
        }
    }

    /**
     * A discount on an inclusive invoice is given as the customer sees it.
     */
    public function test_an_inclusive_discount_is_gross(): void
    {
        $totals = InvoiceTotals::of([
            $this->line('1', '115.00'),
            $this->line('1', '57.50'),
        ], '11.50', inclusive: true);

        $this->assertSame('161.00', $totals->total);
        $this->assertSame('150.00', $totals->subtotal);
        $this->assertSame('10.00', $totals->discount);
        $this->assertSame('21.00', $totals->tax);
    }

    public function test_mixed_rates_stay_exact(): void
    {
        $totals = InvoiceTotals::of([
            $this->line('2', '23.00'),
            ['quantity' => '1', 'unit_price' => '40.00', 'tax_rate' => '0', 'tax_category' => 'Z'],
        ], '6.33', inclusive: true);

        $this->assertSame('79.67', $totals->total);
        $this->assertSame($totals->total, bcadd(bcsub($totals->subtotal, $totals->discount, 2), $totals->tax, 2));
    }

    /**
     * ZATCA's own example: "The price inclusive of VAT was SAR 1,255.00 ...
     * The VAT amount calculated as a fraction of 15/115 of the VAT-inclusive
     * price is SAR 163.70", leaving 1,091.30 taxable (Guideline for Tax
     * Invoicing and Records, v3, section 4.2.4, Example 6).
     */
    public function test_zatcas_inclusive_example(): void
    {
        $totals = InvoiceTotals::of([$this->line('1', '1255.00')], inclusive: true);

        $this->assertSame(['1091.30', '0.00', '163.70', '1255.00'], $this->figures($totals));
    }

    /**
     * Inclusive invoices of every shape stay inside what ZATCA allows.
     *
     * The VAT is what the gross leaves over the net, so a category's VAT can
     * sit off its base times its rate. ZATCA's check on that (BR-S-09 and
     * BR-CO-17 in its SDK) allows one halalah per invoice line, and it warns
     * rather than rejects - but warnings are investigated, so this keeps
     * clear of it: random invoices, mixed rates, discounts, the customer's
     * total exact every time.
     */
    public function test_random_invoices_stay_in_tolerance(): void
    {
        mt_srand(20261015);
        $rates = [['S', '15'], ['Z', '0'], ['S', '5']];

        for ($n = 0; $n < 400; $n++) {
            $lines = [];
            $gross = '0';

            for ($i = 0, $count = mt_rand(1, 6); $i < $count; $i++) {
                [$category, $rate] = $rates[mt_rand(0, 2)];
                $quantity = (string) mt_rand(1, 40);
                $price = number_format(mt_rand(1, 500000) / 100, 2, '.', '');
                $lines[] = ['quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => $rate, 'tax_category' => $category];
                $gross = bcadd($gross, bcmul($quantity, $price, 2), 2);
            }

            $discount = mt_rand(0, 1) ? number_format(mt_rand(0, (int) ((float) $gross * 30)) / 100, 2, '.', '') : '0';
            $totals = InvoiceTotals::of($lines, $discount, inclusive: true);

            $this->assertSame(bcsub($gross, $discount, 2), $totals->total, "invoice {$n}: the customer's total moved");

            $nets = [];
            $byRate = [];
            foreach ($totals->lines as $i => $line) {
                $key = $lines[$i]['tax_category'].'_'.$lines[$i]['tax_rate'];
                $nets[$key] = ($nets[$key] ?? 0.0) + (float) $line['net'];
                $byRate[$key] = (float) $lines[$i]['tax_rate'];
            }

            $categories = InvoiceTotals::categories($nets, $byRate, (float) $totals->discount, (float) $totals->tax);
            $tolerance = count($lines) * 0.01 + 0.000001;

            foreach ($categories as $key => $category) {
                $expected = round($category['base'] * $byRate[$key] / 100, 2);
                $this->assertLessThanOrEqual($tolerance, abs($category['tax'] - $expected), "invoice {$n}, {$key}");
            }

            $this->assertSame((float) $totals->tax, round(array_sum(array_column($categories, 'tax')), 2), "invoice {$n}: BR-CO-14");
        }
    }

    /**
     * A line read back from storage carries its exact net, which is used in
     * place of quantity times the rounded price.
     */
    public function test_a_stored_net_is_used(): void
    {
        $totals = InvoiceTotals::of([['quantity' => '3', 'unit_price' => '8.70', 'net' => '26.09', 'tax_rate' => '15']]);

        $this->assertSame('26.09', $totals->subtotal);
    }

    /**
     * @return array{quantity: string, unit_price: string, tax_rate: string, tax_category: string}
     */
    private function line(string $quantity, string $price): array
    {
        return ['quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => '15', 'tax_category' => 'S'];
    }

    /**
     * @return list<string>
     */
    private function figures(InvoiceTotals $totals): array
    {
        return [$totals->subtotal, $totals->discount, $totals->tax, $totals->total];
    }
}
