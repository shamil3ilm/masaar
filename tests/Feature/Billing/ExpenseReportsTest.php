<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ExpenseEntry;
use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Services\ExpenseReports;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Expense reports in riyals: by category, supplier, month and cost centre;
 * the VAT return's figures with reverse charge on both sides; profit and
 * loss by month; payables by age - on the page and as CSVs. Drafts and
 * void expenses count nowhere.
 */
class ExpenseReportsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));
        $this->from = CarbonImmutable::parse('2026-10-01');
        $this->to = CarbonImmutable::parse('2026-10-31');

        // The platform sold 1000 SAR before VAT: 150 SAR output VAT.
        $platform = Organization::create([
            'name' => Brand::name('en').' Platform', 'name_ar' => 'منصة '.Brand::name('ar'), 'country' => 'SA',
            'vat_number' => '300000000000003', 'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        config(['billing.organization_id' => $platform->id]);
        $this->mock(InvoiceIssuer::class, fn ($mock) => $mock->shouldReceive('issue')->andReturn(IssueOutcome::issuedOnly()));
        $acme = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'vat_number' => '310122393500003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $receivable = app(Receivables::class)->issue($acme, new ReceivableDraft('Subscription', 'اشتراك', '1000'), null);
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($receivable->invoice_id)->sole()
            ->forceFill(['invoice_number' => 'PLT-1', 'status' => 'accepted', 'hash' => base64_encode(hash('sha256', 'PLT-1', true))])->save());

        $suppliers = app(Suppliers::class);
        $hosting = $suppliers->save(null, ['name' => 'Cloud Hosting Ltd', 'country' => 'IE', 'currency' => 'USD', 'payment_terms_days' => 0, 'category' => 'hosting'], null);
        $office = $suppliers->save(null, ['name' => 'Riyadh Office Supplies', 'country' => 'SA', 'currency' => 'SAR', 'payment_terms_days' => 30, 'category' => 'office'], null);

        // 100 USD at 3.75 reverse charge; 1150 SAR with 150 recoverable;
        // 230 SAR whose 30 VAT is a cost. A draft and a void one count nowhere.
        $this->record($hosting, '100', '0', VatTreatment::ReverseCharge, 'hosting', 'platform', 'USD', '3.75', 'H-1');
        $this->record($office, '1000', '150', VatTreatment::Recoverable, 'office', 'administration', number: 'O-1');
        $this->record($office, '200', '30', VatTreatment::NotRecoverable, 'software', 'administration', number: 'O-2');
        $this->record($office, '5000', '750', VatTreatment::Recoverable, 'office', null, number: 'O-3', draft: true);
        app(Expenses::class)->void($this->record($office, '9000', '1350', VatTreatment::Recoverable, 'office', null, number: 'O-4'), 'Entered twice', null);
    }

    public function test_expenses_grouped_four_ways(): void
    {
        $reports = app(ExpenseReports::class);

        $categories = collect($reports->grouped('category', $this->from, $this->to))->keyBy('key');
        $this->assertSame(['1000.00', '375.00', '230.00'], [$categories['office']['cost'], $categories['hosting']['cost'], $categories['software']['cost']]);
        $this->assertSame('56.25', $categories['hosting']['vat']);

        $this->assertSame(['Riyadh Office Supplies', 'Cloud Hosting Ltd'], array_column($reports->grouped('supplier', $this->from, $this->to), 'name'));
        $this->assertSame([['2026-10', 3, '1605.00']], array_map(fn (array $row): array => [$row['key'], $row['count'], $row['cost']], $reports->grouped('month', $this->from, $this->to)));
        $this->assertSame('1230.00', collect($reports->grouped('cost_centre', $this->from, $this->to))->keyBy('key')['administration']['cost']);
    }

    /** Output 150 + 56.25, input 150 + 56.25: nothing to pay. */
    public function test_the_vat_return_draft(): void
    {
        $summary = app(ExpenseReports::class)->vat($this->from, $this->to)['summary'];

        $this->assertSame(['150.00', '375.00', '56.25', '150.00', '56.25', '30.00'], [
            $summary['sales_output'], $summary['reverse_charge_base'], $summary['reverse_charge_output'],
            $summary['input_recoverable'], $summary['reverse_charge_input'], $summary['input_not_recoverable'],
        ]);
        $this->assertSame(['206.25', '206.25', '0.00'], [$summary['output_total'], $summary['input_total'], $summary['net_payable']]);
    }

    public function test_profit_and_loss_by_month(): void
    {
        $pnl = app(ExpenseReports::class)->profitAndLoss(CarbonImmutable::parse('2026-09-01'), $this->to);

        $this->assertSame(['2026-09', '2026-10'], array_column($pnl['months'], 'month'));
        $this->assertSame(['1000.00', '1605.00', '-605.00'], [$pnl['totals']['revenue'], $pnl['totals']['costs'], $pnl['totals']['profit']]);
    }

    public function test_the_page_and_its_csvs(): void
    {
        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $support->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $period = ['from' => '2026-10-01', 'to' => '2026-10-31'];

        $this->actingAs($support)->get(route('admin.finance.expense-reports', $period))
            ->assertOk()->assertSee('data-vat-return', false)->assertSee('206.25')->assertSee('-605.00');

        foreach (['category' => 'Hosting and infrastructure', 'supplier' => 'Cloud Hosting Ltd', 'month' => '2026-10', 'cost_centre' => 'Administration',
            'vat' => 'H-1', 'pnl' => '-605.00', 'payables' => 'Riyadh Office Supplies'] as $report => $expected) {
            $csv = $this->actingAs($support)->get(route('admin.finance.expense-reports.csv', ['report' => $report, ...$period]))->assertOk()->streamedContent();
            $this->assertStringContainsString($expected, $csv, $report.' CSV');
        }
    }

    private function record(Supplier $supplier, string $net, string $vat, VatTreatment $treatment, string $category, ?string $centre,
        string $currency = 'SAR', ?string $rate = null, ?string $number = null, bool $draft = false): Expense
    {
        return app(Expenses::class)->record(new ExpenseEntry(
            supplierId: (string) $supplier->id,
            expenseDate: CarbonImmutable::parse('2026-10-05'),
            description: 'A bill',
            category: $category,
            currency: $currency,
            net: $net,
            vat: $vat,
            vatTreatment: $treatment,
            supplierInvoiceNumber: $number,
            costCentre: $centre,
            exchangeRate: $rate,
        ), null, $draft, null);
    }
}
