<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Peppol\Services\UaeVatSummary;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A UAE organization's report: sales by UAE tax category in dirhams, credit
 * notes taken off, and the CSVs saying the same - none of the Saudi return.
 */
class UaeVatReportTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gulf = $this->uaeOrganization();

        $this->sale('AE-1', [
            $this->uaeLine(),
            $this->uaeLine(['tax_category' => 'Z', 'tax_rate' => 0, 'tax_amount' => 0, 'net_amount' => 200, 'unit_price' => 100, 'line_total' => 200]),
            $this->uaeLine(['tax_category' => 'E', 'tax_rate' => 0, 'tax_amount' => 0, 'net_amount' => 100, 'unit_price' => 50, 'line_total' => 100]),
            $this->uaeLine(['tax_category' => 'AE', 'tax_rate' => 5, 'tax_amount' => 0, 'net_amount' => 300, 'unit_price' => 150, 'line_total' => 300]),
        ]);
        $this->sale('AE-2', [$this->uaeLine(['net_amount' => 100, 'unit_price' => 50, 'tax_amount' => 5, 'line_total' => 105])], ['currency' => 'USD', 'exchange_rate' => '3.6725']);
        $this->sale('AE-3', [$this->uaeLine(['net_amount' => 200, 'unit_price' => 100, 'tax_amount' => 10, 'line_total' => 210])], ['document_type' => 'credit_note', 'billing_ref' => 'AE-1']);
        $this->sale('AE-4', [$this->uaeLine()], ['issue_date' => now()->subYear()->toDateString()]);
    }

    public function test_sales_sum_by_uae_category(): void
    {
        $summary = app(UaeVatSummary::class)->for((string) $this->gulf->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());

        $this->assertSame('AED', $summary['currency']);
        $this->assertSame(['label' => 'Standard rate', 'base' => 1167.25, 'vat' => 58.36], $summary['categories']['S']);
        $this->assertSame(['label' => 'Zero-rated', 'base' => 200.0, 'vat' => 0.0], $summary['categories']['Z']);
        $this->assertSame(['label' => 'Exempt', 'base' => 100.0, 'vat' => 0.0], $summary['categories']['E']);
        $this->assertSame(['label' => 'Reverse charge', 'base' => 300.0, 'vat' => 0.0], $summary['categories']['AE']);
        $this->assertSame(3, $summary['documents']);
        $this->assertSame(1, $summary['credit_notes']);
        $this->assertSame(1, $summary['converted']);
    }

    /**
     * A line in a category the UAE does not use is listed for attention; the
     * rest of the report stands.
     */
    public function test_unknown_category_is_listed(): void
    {
        $this->sale('AE-9', [$this->uaeLine(['tax_category' => 'K'])]);

        $summary = app(UaeVatSummary::class)->for((string) $this->gulf->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());

        $this->assertSame(['AE-9'], $summary['unreadable']);
        $this->assertSame(3, $summary['documents']);
        $this->assertSame(1167.25, $summary['categories']['S']['base']);

        $this->asRequest(fn () => $this->portal()->get(route('portal.reports'))
            ->assertOk()
            ->assertSee('a tax category the UAE does not use')
            ->assertSee('AE-9'));
    }

    public function test_portal_shows_the_uae_summary(): void
    {
        $this->asRequest(fn () => $this->portal()->get(route('portal.reports'))
            ->assertOk()
            ->assertSee('Amount (AED)')
            ->assertSee('1,167.25')
            ->assertSee('not the FTA VAT return form')
            ->assertDontSee('(SAR)')
            ->assertDontSee('Standard rated sales'));
    }

    public function test_csvs_follow_the_uae_split(): void
    {
        $vat = $this->asRequest(fn () => $this->portal()->get(route('portal.reports.vat'))->assertOk()->streamedContent());

        $this->assertStringContainsString('S,"Standard rate",1167.25,58.36', $vat);
        $this->assertStringContainsString('AE,"Reverse charge",300.00,0.00', $vat);
        $this->assertStringContainsString('not the FTA VAT return form', $vat);
        $this->assertStringNotContainsString('SAR', $vat);

        $invoices = $this->asRequest(fn () => $this->portal()->get(route('portal.reports.invoices'))->assertOk()->streamedContent());

        $this->assertStringContainsString('"Total (AED)",Status', $invoices);
        $this->assertStringNotContainsString('Counter', $invoices);
        $this->assertMatchesRegularExpression('/AE-2,.*,USD,100\.00,5\.00,105\.00,367\.25,18\.36,385\.61,issued\r?\n/', $invoices);
        $this->assertMatchesRegularExpression('/AE-3,.*,AED,-200\.00,-10\.00,-210\.00,-200\.00,-10\.00,-210\.00,issued/', $invoices);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attributes
     */
    private function sale(string $number, array $lines, array $attributes = []): Invoice
    {
        $net = array_sum(array_column($lines, 'net_amount'));
        $tax = array_sum(array_column($lines, 'tax_amount'));

        return $this->uaeInvoice($this->gulf, [
            'invoice_number' => $number,
            'issue_date' => now()->toDateString(),
            'subtotal' => number_format($net, 2, '.', ''),
            'tax_amount' => number_format($tax, 2, '.', ''),
            'total' => number_format($net + $tax, 2, '.', ''),
            ...$attributes,
        ], $lines);
    }

    private function portal(): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->gulf->id, ['role' => 'viewer', 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->gulf->id]);
    }
}
