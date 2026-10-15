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
