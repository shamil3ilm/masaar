<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pipeline's money arithmetic.
 *
 * ZATCA reconciles an invoice to the halalah and rejects one whose totals do
 * not add up, so a rounding error here is a rejected tax document rather than
 * a display artefact. Everything is computed with bcmath on strings; these
 * assert the results a float implementation would get wrong.
 */
class InvoiceDrafterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Acme',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);
    }

    public function test_totals_add_up(): void
    {
        $invoice = $this->draft([
            ['description' => 'Widget', 'quantity' => 2, 'unit_price' => '50.00'],
        ]);

        $this->assertSame('100.00', $invoice->subtotal);
        $this->assertSame('15.00', $invoice->tax_amount);
        $this->assertSame('115.00', $invoice->total);
    }

    /**
     * 0.1 + 0.2 is the canonical float failure. In halalah it must be exact.
     */
    public function test_fractional_amounts_stay_exact(): void
    {
        $invoice = $this->draft([
            ['description' => 'A', 'quantity' => 1, 'unit_price' => '0.10'],
            ['description' => 'B', 'quantity' => 1, 'unit_price' => '0.20'],
        ]);

        $this->assertSame('0.30', $invoice->subtotal);
    }

    /**
     * Many small lines are where float drift compounds into a visible gap.
     */
    public function test_many_lines_do_not_drift(): void
    {
        $lines = array_fill(0, 100, [
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => '0.07',
        ]);

        $invoice = $this->draft($lines);

        $this->assertSame('7.00', $invoice->subtotal);
    }

    public function test_discount_applies_before_tax(): void
    {
        $invoice = $this->draft(
            [['description' => 'Widget', 'quantity' => 1, 'unit_price' => '100.00']],
            ['discount_amount' => '10.00']
        );

        // VAT is charged on what the buyer pays for, after the discount.
        $this->assertSame('100.00', $invoice->subtotal);
        $this->assertSame('13.50', $invoice->tax_amount);
        $this->assertSame('103.50', $invoice->total);
    }

    public function test_zero_rated_line_carries_no_tax(): void
    {
        $invoice = $this->draft([
            ['description' => 'Export', 'quantity' => 1, 'unit_price' => '100.00', 'tax_rate' => 0],
        ]);

        $this->assertSame('0.00', $invoice->tax_amount);
        $this->assertSame('100.00', $invoice->total);
    }

    /**
     * bcmath truncates, so VAT has to be rounded explicitly. 3 x 19.99 at 15%
     * is 8.9955, which truncates to 8.99 and rounds to 9.00. ZATCA reconciles
     * line VAT against taxable amount times rate, and the truncated figure
     * fails that check.
     */
    public function test_vat_rounds_half_up(): void
    {
        $invoice = $this->draft([
            ['description' => 'Widget', 'quantity' => 3, 'unit_price' => '19.99'],
        ]);

        $this->assertSame('59.97', $invoice->subtotal);
        $this->assertSame('9.00', $invoice->tax_amount);
        $this->assertSame('68.97', $invoice->total);
    }

    /**
     * Document VAT is the category base times the rate, not the sum of line
     * VAT. Line VAT here is 9.00 and 4.55; 90.28 at 15% is 13.542, so 13.54.
     * BR-CO-14 compares the document VAT with the category amount.
     */
    public function test_vat_is_taxed_per_category(): void
    {
        $invoice = $this->draft([
            ['description' => 'A', 'quantity' => 3, 'unit_price' => '19.99'],
            ['description' => 'B', 'quantity' => 7, 'unit_price' => '4.33'],
        ]);

        $this->assertSame(['9.00', '4.55'], $invoice->lines->pluck('tax_amount')->all());
        $this->assertSame('13.54', $invoice->tax_amount);
        $this->assertSame('103.82', $invoice->total);
    }

    public function test_lines_are_persisted(): void
    {
        $invoice = $this->draft([
            ['description' => 'A', 'quantity' => 1, 'unit_price' => '10.00'],
            ['description' => 'B', 'quantity' => 1, 'unit_price' => '20.00'],
        ]);

        $this->assertCount(2, $invoice->lines);
    }

    private function draft(array $lines, array $extra = []): Invoice
    {
        $invoice = app(InvoiceDrafter::class)->draft(array_merge([
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'lines' => $lines,
        ], $extra), $this->organization->id);

        return $invoice->fresh(['lines']);
    }
}
