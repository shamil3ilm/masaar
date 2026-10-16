<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
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
        $this->issued([['net' => 50, 'rate' => 0, 'category' => 'Z', 'exempt' => 'VATEX-SA-34-3']]);
        $this->issued([['net' => 30, 'rate' => 0, 'category' => 'Z', 'exempt' => 'VATEX-SA-35']]);
        $this->issued([['net' => 40, 'rate' => 0, 'category' => 'Z', 'exempt' => 'VATEX-SA-HEA']]);
        $this->issued([['net' => 20, 'rate' => 0, 'category' => 'E', 'exempt' => 'VATEX-SA-29']]);
        $this->issued([['net' => 10, 'rate' => 0, 'category' => 'O', 'exempt' => 'VATEX-SA-OOS']]);

        $summary = $this->summary();

        $this->assertSame(['base' => 100.0, 'vat' => 15.0], $this->box($summary, 1));
        $this->assertSame(['base' => 40.0, 'vat' => 0.0], $this->box($summary, 2));
        $this->assertSame(['base' => 30.0, 'vat' => 0.0], $this->box($summary, 3));
        $this->assertSame(['base' => 50.0, 'vat' => 0.0], $this->box($summary, 4));
        $this->assertSame(['base' => 20.0, 'vat' => 0.0], $this->box($summary, 5));
        $this->assertSame(10.0, $summary['out_of_scope']);
        $this->assertSame(240.0, $summary['total_base']);
    }

    public function test_a_credit_note_is_taken_off(): void
    {
        $this->issued([['net' => 100, 'rate' => 15, 'category' => 'S']]);
        $this->issued([['net' => 20, 'rate' => 15, 'category' => 'S']], ['document_type' => 'credit_note']);

        $summary = $this->summary();

        $this->assertSame(['base' => 80.0, 'vat' => 12.0], $this->box($summary, 1));
        $this->assertSame(1, $summary['credit_notes']);
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

        $this->assertStringContainsString('1,"Standard rated sales",100.00,15.00', $csv);
    }

    /**
     * @param  list<array{net: int|float, rate: int|float, category: string, exempt?: string}>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function issued(array $lines, array $overrides = []): Invoice
    {
        $this->counter++;
        $net = array_sum(array_column($lines, 'net'));
        $tax = array_sum(array_map(fn (array $line): float => $line['net'] * $line['rate'] / 100, $lines));

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-'.$this->counter,
            'type' => 'simplified',
            'document_type' => $overrides['document_type'] ?? 'invoice',
            'billing_ref' => ($overrides['document_type'] ?? 'invoice') === 'credit_note' ? 'ACME-1' : null,
            'adjustment_reason' => ($overrides['document_type'] ?? 'invoice') === 'credit_note' ? 'Returned goods' : null,
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
