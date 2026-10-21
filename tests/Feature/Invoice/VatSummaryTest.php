<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Models\InvoicePrepayment;
use App\Domains\Invoice\Services\VatSummary;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A period's sales grouped as the VAT return asks, and the CSV behind it.
 */
class VatSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
    }

    public function test_sales_land_in_their_boxes(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']]);
        $this->issued([['net' => 35, 'rate' => 0, 'category' => 'Z', 'exempt' => 'VATEX-SA-32']]);
        $this->issued([['net' => 15, 'rate' => 0, 'category' => 'Z', 'exempt' => 'VATEX-SA-33']]);
        $this->issued([['net' => 30, 'rate' => 0, 'category' => 'Z', 'exempt' => 'VATEX-SA-35']]);
        // International transport is zero rated, not an export.
        $this->issued([['net' => 5, 'rate' => 0, 'category' => 'Z', 'exempt' => 'VATEX-SA-34-3']]);
        $this->issued([['net' => 40, 'rate' => 0, 'category' => 'Z', 'exempt' => 'VATEX-SA-HEA']]);
        $this->issued([['net' => 20, 'rate' => 0, 'category' => 'E', 'exempt' => 'VATEX-SA-29']]);
        $this->issued([['net' => 10, 'rate' => 0, 'category' => 'O', 'exempt' => 'VATEX-SA-OOS']]);

        $summary = $this->summary();

        $this->assertSame(['base' => 100.0, 'vat' => 15.0], $this->box($summary, 1));
        $this->assertSame(['base' => 40.0, 'vat' => 0.0], $this->box($summary, 2));
        $this->assertSame(['base' => 35.0, 'vat' => 0.0], $this->box($summary, 3));
        $this->assertSame(['base' => 50.0, 'vat' => 0.0], $this->box($summary, 4));
        $this->assertSame(['base' => 20.0, 'vat' => 0.0], $this->box($summary, 5));
        $this->assertSame(10.0, $summary['out_of_scope']);
        $this->assertSame(245.0, $summary['total_base']);
    }

    public function test_a_credit_note_is_taken_off(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']]);
        $this->issued([['net' => 20, 'rate' => 15, 'category' => 'S']], ['document_type' => 'credit_note']);

        $summary = $this->summary();

        $this->assertSame(['base' => 80.0, 'vat' => 12.0], $this->box($summary, 1));
        $this->assertSame(1, $summary['credit_notes']);
    }

    /** Said on the page, so an empty Adjustments column is not a surprise. */
    public function test_the_page_says_where_credits_go(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']]);
        $this->issued([['net' => 20, 'rate' => 15, 'category' => 'S']], ['document_type' => 'credit_note']);

        $this->portal('viewer')->get(route('portal.reports'))->assertOk()
            ->assertSee('A credit note for an invoice of this period is taken off its box; Adjustments hold only those for an earlier period.');
    }

    /**
     * The document discount comes off the base, as it did on the invoice.
     */
    public function test_the_discount_is_shared_out(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']], ['discount_amount' => '10.00', 'tax_amount' => '13.50']);

        $this->assertSame(['base' => 90.0, 'vat' => 13.5], $this->box($this->summary(), 1));
    }

    /**
     * Only issued documents in the period: no draft, nothing ZATCA rejected,
     * nothing from another period.
     */
    public function test_drafts_and_rejections_do_not_count(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']]);
        $this->issued([['net' => 999, 'rate' => 15, 'category' => 'S']], ['status' => 'rejected']);
        $this->issued([['net' => 999, 'rate' => 15, 'category' => 'S']], ['issue_date' => now()->subYear()->toDateString()]);
        $this->issued([['net' => 999, 'rate' => 15, 'category' => 'S']], ['status' => 'draft', 'hash' => null, 'icv' => null]);

        $this->assertSame(['base' => 100.0, 'vat' => 15.0], $this->box($this->summary(), 1));
    }

    public function test_foreign_currency_is_converted(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']], ['currency' => 'USD', 'exchange_rate' => '3.75']);

        $summary = $this->summary();

        $this->assertSame(['base' => 375.0, 'vat' => 56.25], $this->box($summary, 1));
        $this->assertSame(1, $summary['converted']);
    }

    /**
     * A credit note against an invoice from a period already returned goes
     * in Adjustments; a debit note stays in the amount ([GL] §7.1.1).
     */
    public function test_earlier_period_credits_are_adjustments(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']], ['issue_date' => now()->subMonthNoOverflow()->toDateString()]);
        $this->issued([['net' => 200, 'rate' => 15, 'category' => 'S']]);
        $this->issued([['net' => 20, 'rate' => 15, 'category' => 'S']], ['document_type' => 'credit_note', 'billing_ref' => 'ACME-1']);
        $this->issued([['net' => 50, 'rate' => 15, 'category' => 'S']], ['document_type' => 'credit_note', 'billing_ref' => 'ACME-2']);
        $this->issued([['net' => 10, 'rate' => 15, 'category' => 'S']], ['document_type' => 'debit_note', 'billing_ref' => 'ACME-1']);

        $summary = $this->summary();

        $this->assertSame(['base' => 160.0, 'vat' => 24.0], $this->box($summary, 1));
        $this->assertSame(-20.0, $summary['boxes'][1]['adjustment_base']);
        $this->assertSame(-3.0, $summary['boxes'][1]['adjustment_vat']);
        $this->assertSame(140.0, $summary['net_base']);
        $this->assertSame(21.0, $summary['net_vat']);
        $this->assertSame(1, $summary['adjustments']);

        $this->portal('viewer')->get(route('portal.reports'))
            ->assertOk()
            ->assertSee('Adjustments (SAR)')
            ->assertSee('-20.00');
    }

    /**
     * The tax point is the earlier of supply and issue ([GL] §4.5.1); a
     * note stays on its issue date.
     */
    public function test_an_invoice_counts_when_supplied(): void
    {
        $lastMonth = now()->subMonthNoOverflow()->toDateString();

        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']], ['issue_date' => now()->addMonthNoOverflow()->toDateString(), 'supply_date' => now()->toDateString()]);
        $this->issued([['net' => 999, 'rate' => 15, 'category' => 'S']], ['supply_date' => $lastMonth]);
        $this->issued([['net' => 20, 'rate' => 15, 'category' => 'S']], ['document_type' => 'credit_note', 'supply_date' => $lastMonth]);
        // A supply over a period is due when invoiced ([GL] §4.5.2).
        $this->issued([['net' => 50, 'rate' => 15, 'category' => 'S']], ['supply_date' => $lastMonth, 'supply_end_date' => now()->toDateString()]);

        $this->assertSame(['base' => 130.0, 'vat' => 19.5], $this->box($this->summary(), 1));
    }

    /**
     * Converting at 1 would misstate the return, so the document is named.
     */
    public function test_a_missing_rate_is_flagged(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']]);
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']], ['currency' => 'USD', 'exchange_rate' => null]);

        $summary = $this->summary();

        $this->assertSame(['base' => 100.0, 'vat' => 15.0], $this->box($summary, 1));
        $this->assertSame(['ACME-2'], $summary['missing_rate']);
        $this->assertSame(1, $summary['documents']);

        $this->portal('viewer')->get(route('portal.reports'))
            ->assertOk()
            ->assertSee('have no exchange rate')
            ->assertSee('ACME-2');

        $csv = $this->portal('member')->get(route('portal.reports.invoices'))->assertOk()->streamedContent();

        $this->assertMatchesRegularExpression('/ACME-2,.*,USD,100\.00,15\.00,115\.00,,,,issued/', $csv);
    }

    /**
     * Riyal columns, and credit notes taken off, so the file adds up to the
     * summary.
     */
    public function test_the_csv_reconciles_in_riyals(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']], ['currency' => 'USD', 'exchange_rate' => '3.75']);
        $this->issued([['net' => 20, 'rate' => 15, 'category' => 'S']], ['document_type' => 'credit_note']);

        $csv = $this->portal('member')->get(route('portal.reports.invoices'))->assertOk()->streamedContent();

        $this->assertStringContainsString('"Total (SAR)"', $csv);
        $this->assertMatchesRegularExpression('/ACME-1,.*,USD,100\.00,15\.00,115\.00,375\.00,56\.25,431\.25,issued/', $csv);
        $this->assertMatchesRegularExpression('/ACME-2,.*,SAR,-20\.00,-3\.00,-23\.00,-20\.00,-3\.00,-23\.00,issued/', $csv);
    }

    /**
     * An advance of 1000 (150 VAT), its final invoice of 3000 (450 VAT)
     * that deducts it, and a sale of 100 standard plus 10 out of scope.
     * The summary: 3100 and 465 in box 1, 10 out of scope. The file's
     * taxable and VAT columns add up to the same - 1000 + 2000 + 100 and
     * 150 + 300 + 15 - with the 10 in its own column.
     */
    public function test_invoice_csv_ties_to_summary(): void
    {
        $advance = $this->issued([['net' => 1000, 'rate' => 15, 'category' => 'S']], ['document_type' => 'prepayment']);
        $this->issued([['net' => 3000, 'rate' => 15, 'category' => 'S']], prepayments: [[
            'prepayment_invoice_id' => $advance->id, 'position' => 1, 'invoice_number' => 'ACME-1', 'issue_date' => now()->toDateString(), 'issued_at' => now(),
            'tax_category' => 'S', 'tax_rate' => '15', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00',
        ]]);
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S'], ['net' => 10, 'rate' => 0, 'category' => 'O', 'exempt' => 'VATEX-SA-OOS']]);

        $summary = $this->summary();
        $this->assertSame([3100.0, 465.0, 10.0], [$summary['total_base'], $summary['total_vat'], $summary['out_of_scope']]);

        $csv = $this->portal('member')->get(route('portal.reports.invoices'))->assertOk()->streamedContent();

        $this->assertStringContainsString('"Out of scope (SAR)"', $csv);
        $this->assertMatchesRegularExpression('/ACME-1,.*,SAR,1000\.00,150\.00,1150\.00,1000\.00,150\.00,1150\.00,issued,1,0\.00,0\.00/', $csv);
        $this->assertMatchesRegularExpression('/ACME-2,.*,SAR,2000\.00,300\.00,3450\.00,2000\.00,300\.00,3450\.00,issued,2,0\.00,0\.00/', $csv);
        $this->assertMatchesRegularExpression('/ACME-3,.*,SAR,100\.00,15\.00,125\.00,100\.00,15\.00,125\.00,issued,3,10\.00,10\.00/', $csv);
    }

    public function test_a_viewer_sees_the_report(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']]);

        $this->portal('viewer')->get(route('portal.reports'))
            ->assertOk()
            ->assertSee('Standard rated sales')
            ->assertSee('100.00');
    }

    /**
     * A buyer named like a formula must not run when the file is opened.
     */
    public function test_the_csv_is_safe_to_open(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']], ['buyer_name' => '=HYPERLINK("http://x","y")']);

        $csv = $this->portal('member')->get(route('portal.reports.invoices'))->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Invoice number",Type', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_the_vat_csv_downloads(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']]);

        $csv = $this->portal('member')->get(route('portal.reports.vat'))->assertOk()->streamedContent();

        $this->assertStringContainsString('1,"Standard rated sales",100.00,0.00,15.00,0.00', $csv);
        $this->assertStringContainsString(',"Net of adjustments",100.00,,15.00,', $csv);
    }

    /**
     * @param  list<array{net: int|float, rate: int|float, category: string, exempt?: string}>  $lines
     * @param  array<string, mixed>  $overrides
     * @param  list<array<string, mixed>>  $prepayments  advances a final invoice deducts
     */
    private function issued(array $lines, array $overrides = [], array $prepayments = []): Invoice
    {
        $this->counter++;
        $net = array_sum(array_column($lines, 'net'));
        $tax = array_sum(array_map(fn (array $line): float => $line['net'] * $line['rate'] / 100, $lines));

        $note = in_array($overrides['document_type'] ?? 'invoice', ['credit_note', 'debit_note'], true);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-'.$this->counter,
            'type' => 'simplified',
            'document_type' => $overrides['document_type'] ?? 'invoice',
            'billing_ref' => $note ? ($overrides['billing_ref'] ?? 'ACME-1') : null,
            'adjustment_reason' => $note ? 'Returned goods' : null,
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => number_format($net, 2, '.', ''),
            'tax_amount' => number_format($tax, 2, '.', ''),
            'total' => number_format($net + $tax, 2, '.', ''),
        ]));

        foreach ($lines as $line) {
            $lineTax = $line['net'] * $line['rate'] / 100;
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => 'Item',
                'quantity' => '1',
                'unit_price' => number_format($line['net'], 2, '.', ''),
                'net_amount' => number_format($line['net'], 2, '.', ''),
                'tax_rate' => (string) $line['rate'],
                'tax_amount' => number_format($lineTax, 2, '.', ''),
                'tax_category' => $line['category'],
                'exempt_code' => $line['exempt'] ?? null,
                'exempt_reason' => isset($line['exempt']) ? 'Reason' : null,
                'line_total' => number_format($line['net'] + $lineTax, 2, '.', ''),
            ]);
        }

        foreach ($prepayments as $prepayment) {
            InvoicePrepayment::query()->create(['invoice_id' => $invoice->id, ...$prepayment]);
        }

        $invoice->forceFill(array_merge([
            'status' => 'issued',
            'icv' => $this->counter,
            'hash' => base64_encode(hash('sha256', 'doc'.$this->counter, true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
        ], array_diff_key($overrides, ['document_type' => true])))->save();

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return app(VatSummary::class)->for((string) $this->acme->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{base: float, vat: float}
     */
    private function box(array $summary, int $box): array
    {
        return ['base' => $summary['boxes'][$box]['base'], 'vat' => $summary['boxes'][$box]['vat']];
    }

    private function portal(string $role): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
