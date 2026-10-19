<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Support\ShareAllocation;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A sale's lines divided between the patient and the insurer: each share
 * the same items at the share of the price, to the halalah, and the two
 * shares always adding up to the sale.
 */
class ShareAllocationTest extends TestCase
{
    public function test_a_percentage_divides_each_unit(): void
    {
        $shares = ShareAllocation::divide([$this->line('3', '33.35')], 'percent', '20');

        // 20% of 33.35 is 6.67; the insurer pays the rest of each unit.
        $this->assertSame([['3', '6.67']], $this->pieces($shares['patient']));
        $this->assertSame([['3', '26.68']], $this->pieces($shares['insurer']));
    }

    public function test_an_amount_is_exact_across_lines(): void
    {
        $shares = ShareAllocation::divide([$this->line('3', '10.00'), $this->line('1', '70.00')], 'amount', '50.00');

        // 50 of 100, in proportion: 15 of the first line and 35 of the second.
        $this->assertSame([['3', '5.00'], ['1', '35.00']], $this->pieces($shares['patient']));
        $this->assertSame([['3', '5.00'], ['1', '35.00']], $this->pieces($shares['insurer']));
        $this->assertSame('50.00', $this->total($shares['patient']));
        $this->assertSame('50.00', $this->total($shares['insurer']));
    }

    /** A share that does not divide by the quantity is two prices, a halalah apart. */
    public function test_an_uneven_amount_splits_the_line(): void
    {
        $shares = ShareAllocation::divide([$this->line('3', '10.00')], 'amount', '5.00');

        $this->assertSame([['2', '1.67'], ['1', '1.66']], $this->pieces($shares['patient']));
        $this->assertSame([['2', '8.33'], ['1', '8.34']], $this->pieces($shares['insurer']));
        $this->assertSame('5.00', $this->total($shares['patient']));
        $this->assertSame('25.00', $this->total($shares['insurer']));
    }

    public function test_lines_go_to_their_payer(): void
    {
        $shares = ShareAllocation::divide([
            [...$this->line('1', '40.00'), 'payer' => 'patient'],
            [...$this->line('2', '100.00'), 'payer' => 'insurer'],
        ], 'lines', null);

        $this->assertSame([['1', '40.00']], $this->pieces($shares['patient']));
        $this->assertSame([['2', '100.00']], $this->pieces($shares['insurer']));
    }

    public function test_each_payer_must_have_a_share(): void
    {
        $this->assertRefused(fn () => ShareAllocation::divide([$this->line('1', '100')], 'amount', '100.00'), 'split.amount');
        $this->assertRefused(fn () => ShareAllocation::divide([$this->line('1', '100')], 'percent', '0'), 'split.percent');
        $this->assertRefused(fn () => ShareAllocation::divide([[...$this->line('1', '100'), 'payer' => 'insurer']], 'lines', null), 'split');
    }

    public function test_a_discounted_line_is_refused(): void
    {
        $this->assertRefused(fn () => ShareAllocation::divide([[...$this->line('1', '100'), 'discount_amount' => '5']], 'percent', '20'), 'lines.0.discount_amount');
    }

    /** @return array<string, mixed> */
    private function line(string $quantity, string $price): array
    {
        return ['description' => 'Item', 'quantity' => $quantity, 'unit_price' => $price, 'tax_category' => 'S', 'tax_rate' => 15];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{string, string}>
     */
    private function pieces(array $lines): array
    {
        return array_map(fn (array $line): array => [(string) $line['quantity'], (string) $line['unit_price']], $lines);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function total(array $lines): string
    {
        return array_reduce($lines, fn (string $sum, array $line): string => bcadd($sum, bcmul((string) $line['quantity'], (string) $line['unit_price'], 2), 2), '0.00');
    }

    private function assertRefused(callable $divide, string $field): void
    {
        try {
            $divide();
            $this->fail("The split was not refused on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
