<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ExpenseEntry;
use App\Domains\Billing\DTOs\OutgoingPayment;
use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\DTOs\ReceivedPayment;
use App\Domains\Billing\Enums\OutgoingChannel;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Services\Credits;
use App\Domains\Billing\Services\ExpenseReports;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\Payments;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\SupplierPayments;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Billing\Settings\FinanceSettings;
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

    /**
     * Every line by month, in riyals. Revenue 1000 + 100 + 200 + 100 AED
     * at 1.02 = 1402; costs 1605. A 50 goodwill credit settles part of the
     * 100 invoice, the 65 left is paid less a 5 bank fee; the 230 invoice
     * is written off; 115 AED invoiced at 1.02 is paid at 1.03, a 1.15
     * gain; 1000 of O-1 is waived by the supplier, a gain. Profit:
     * 1402 - 1605 + 1.15 - 5 - 230 + 1000 - 50 = 513.15.
     */
    public function test_profit_and_loss_counts_every_line(): void
    {
        $acme = Organization::query()->where('name', 'Acme Trading')->sole();
        $settings = app(FinanceSettings::class);
        $settings->setCurrencies(['SAR', 'AED'], 'SAR', null);
        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'name_ar' => 'الخليج للتجارة', 'country' => 'AE', 'vat_number' => '100000000000003', 'city' => 'Dubai']);
        $settings->set(FinanceSettings::CURRENCY, 'AED', null, (string) $gulf->id);
        $receivables = app(Receivables::class);

        app(Credits::class)->grant((string) $acme->id, 'SAR', '50', BillingCredit::ADJUSTMENT, null, 'Goodwill');
        $setup = $this->sealed($receivables->issue($acme, new ReceivableDraft('Setup', 'إعداد', '100'), null), 'PLT-2');
        app(Payments::class)->recordTransfer($acme, new ReceivedPayment('65', 'SAR', CarbonImmutable::now(), 'TRF-1', fee: '5', allocations: [(string) $setup->id => '65']), null);

        $receivables->writeOff($this->sealed($receivables->issue($acme, new ReceivableDraft('Report', 'تقرير', '200'), null), 'PLT-3'), 'Customer closed', null);

        $dirhams = $this->sealed($receivables->issue($gulf, new ReceivableDraft('Subscription', 'اشتراك', '100', exchangeRate: '1.02'), null), 'PLT-4');
        app(Payments::class)->recordTransfer($gulf, new ReceivedPayment('115', 'AED', CarbonImmutable::now(), 'TRF-2', exchangeRate: '1.03', allocations: [(string) $dirhams->id => '115']), null);

        $waived = Expense::query()->where('supplier_invoice_number', 'O-1')->sole();
        app(SupplierPayments::class)->pay($waived, new OutgoingPayment('150', CarbonImmutable::now(), OutgoingChannel::Bank), null);
        app(SupplierPayments::class)->writeOff($waived, 'Supplier waived the rest', null);

        $totals = app(ExpenseReports::class)->profitAndLoss($this->from, $this->to)['totals'];

        $this->assertSame([
            'revenue' => '1402.00', 'costs' => '1605.00', 'exchange' => '0.00', 'customer_exchange' => '1.15', 'fees' => '5.00',
            'bad_debts' => '230.00', 'payables_written_off' => '1000.00', 'goodwill' => '50.00', 'profit' => '513.15',
        ], $totals);

        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $support->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $csv = $this->actingAs($support)->get(route('admin.finance.expense-reports.csv', ['report' => 'pnl', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Bad debts written off', $csv);
        $this->assertStringContainsString('2026-10,1402.00,1605.00,0.00,1.15,5.00,230.00,1000.00,50.00,513.15', $csv);
    }

    /**
     * The gateway's 100 SAR bill (15 VAT deducted) is entered as an
     * expense, and the same fees were kept off a 1150 payment as 5. One
     * source only: from payments, fees 5 and the bill out of costs (1605);
     * from expenses, fees 100 and the 5 ignored. Never 105.
     */
    public function test_fees_come_from_one_source(): void
    {
        $gateway = app(Suppliers::class)->save(null, ['name' => 'Card Gateway Co', 'country' => 'SA', 'currency' => 'SAR', 'payment_terms_days' => 0, 'category' => 'payment_provider'], null);
        $this->record($gateway, '100', '15', VatTreatment::Recoverable, 'payment_provider', null, number: 'G-1');
        $acme = Organization::query()->where('name', 'Acme Trading')->sole();
        app(Payments::class)->recordTransfer($acme, new ReceivedPayment('1150', 'SAR', CarbonImmutable::now(), 'TRF-1', fee: '5'), null);
        $reports = app(ExpenseReports::class);

        $totals = $reports->profitAndLoss($this->from, $this->to)['totals'];
        $this->assertSame(['1605.00', '5.00'], [$totals['costs'], $totals['fees']]);

        $operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $operations->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($operations)->post(route('admin.finance.settings.expenses'), [
            'missing_bill_days' => 7, 'large_payment_threshold' => '10000', 'fee_source' => FinanceSettings::FEES_FROM_EXPENSES, 'fee_categories' => ['payment_provider'],
        ])->assertSessionHas('success');

        $this->assertSame(['payment_provider'], app(FinanceSettings::class)->feeCategories());
        $totals = $reports->profitAndLoss($this->from, $this->to)['totals'];
        $this->assertSame(['1605.00', '100.00'], [$totals['costs'], $totals['fees']]);
        $this->actingAs($operations)->get(route('admin.finance.settings'))->assertOk()->assertSee('Bank and card fees in the profit and loss');
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

    /** What a real issue gives: a number and a hash. */
    private function sealed(Receivable $receivable, string $number): Receivable
    {
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($receivable->invoice_id)->sole()
            ->forceFill(['invoice_number' => $number, 'status' => 'accepted', 'hash' => base64_encode(hash('sha256', $number, true))])->save());

        return $receivable;
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
