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
use App\Domains\Billing\Services\FinanceReports;
use App\Domains\Billing\Services\Payments;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\SupplierPayments;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Books\Models\BadDebtRelief;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\AuthorityAnswer;
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
        AuthorityAnswer::record(Invoice::acrossTenants()->whereKey($receivable->invoice_id)->sole(), 'accepted', ['invoice_number' => 'PLT-1', 'hash' => base64_encode(hash('sha256', 'PLT-1', true))]);

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

    /**
     * The platform's sales come from its country's own VAT return - the one
     * Books drafts - so they are by box and by tax point: PLT-1 in box 1.
     */
    public function test_sales_follow_the_return_boxes(): void
    {
        $boxes = app(ExpenseReports::class)->vat($this->from, $this->to)['sales_boxes'];

        $this->assertSame([['box' => '1', 'amount' => '1000.00', 'adjustment' => '0.00', 'vat' => '150.00']],
            array_map(fn (array $box): array => array_diff_key($box, ['label' => true]), $boxes));
    }

    /**
     * A billing company in the UAE issues documents with no ZATCA hash: they
     * still count as sales and VAT, and an invoice without its receivable
     * is still listed.
     */
    public function test_uae_billing_company_counts(): void
    {
        $gulf = Organization::create(['name' => 'Gulf Platform LLC', 'country' => 'AE']);
        config(['billing.organization_id' => $gulf->id]);
        Invoice::acrossTenants()->create([
            'org_id' => $gulf->id, 'invoice_number' => 'GP-1', 'type' => 'standard', 'document_type' => 'invoice', 'status' => 'issued',
            'issue_date' => '2026-10-10', 'currency' => 'AED', 'buyer_name' => 'Noor LLC', 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00',
        ]);

        $finance = app(FinanceReports::class);

        $this->assertSame(['1000.00', '50.00'], [$finance->vat($this->from, $this->to)['totals']['net'], $finance->vat($this->from, $this->to)['totals']['vat']]);
        $this->assertSame(['GP-1'], array_column($finance->unrecorded(), 'number'));
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

        // The 230 written off is 200 before VAT and 30 of VAT.
        $this->assertSame([
            'revenue' => '1402.00', 'costs' => '1605.00', 'exchange' => '0.00', 'customer_exchange' => '1.15', 'fees' => '5.00',
            'bad_debts' => '200.00', 'bad_debt_vat' => '30.00', 'vat_relief' => '0.00', 'payables_written_off' => '1000.00', 'goodwill' => '50.00', 'profit' => '513.15',
        ], $totals);
        $this->assertSame('30.00', app(ExpenseReports::class)->vat($this->from, $this->to)['summary']['written_off_vat']);

        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $support->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $csv = $this->actingAs($support)->get(route('admin.finance.expense-reports.csv', ['report' => 'pnl', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Bad debts written off', $csv);
        $this->assertStringContainsString('2026-10,1402.00,1605.00,0.00,1.15,5.00,200.00,30.00,0.00,1000.00,50.00,513.15', $csv);
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

    /**
     * Tie-out, month by month: the revenue in the profit and loss, the
     * sales in the VAT return draft and what was invoiced in receivables
     * are one figure. October: PLT-1, 1,000; November: PLT-2, 500.
     */
    public function test_revenue_ties_to_vat_and_receivables(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'UTC'));
        $acme = Organization::query()->where('name', 'Acme Trading')->sole();
        $this->sealed(app(Receivables::class)->issue($acme, new ReceivableDraft('Setup', 'إعداد', '500'), null), 'PLT-2');
        $reports = app(ExpenseReports::class);

        foreach (['2026-10' => '1000.00', '2026-11' => '500.00'] as $month => $net) {
            $from = CarbonImmutable::parse($month.'-01');
            $to = $from->endOfMonth()->startOfDay();

            $this->assertSame([$net, $net, $net], [
                $reports->profitAndLoss($from, $to)['totals']['revenue'],
                $reports->vat($from, $to)['summary']['sales_net'],
                app(FinanceReports::class)->receivables($from, $to)['SAR']['net'],
            ], $month);
        }
    }

    /**
     * A 920 subscription for 16 October to 15 January (92 days) is
     * recognised 160, 300 and 310 in October to December, 150 left deferred;
     * PLT-1 (1,000, no period) is October's whole.
     */
    public function test_subscription_revenue_is_deferred_by_period(): void
    {
        $acme = Organization::query()->where('name', 'Acme Trading')->sole();
        $this->sealed(app(Receivables::class)->issue($acme, new ReceivableDraft('Subscription', 'اشتراك', '920',
            CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2027-01-15')), null), 'PLT-5');

        $months = app(ExpenseReports::class)->deferral($this->from, CarbonImmutable::parse('2026-12-31'))['months'];

        $this->assertSame([
            ['month' => '2026-10', 'invoiced' => '1920.00', 'recognised' => '1160.00', 'deferred' => '760.00'],
            ['month' => '2026-11', 'invoiced' => '0.00', 'recognised' => '300.00', 'deferred' => '460.00'],
            ['month' => '2026-12', 'invoiced' => '0.00', 'recognised' => '310.00', 'deferred' => '150.00'],
        ], $months);
    }

    /**
     * October's journal, every entry balanced: PLT-1 is 1,150 receivable,
     * 1,000 revenue and 150 output VAT; the bills cost 1,605, with 206.25
     * input VAT deducted, 56.25 reverse-charge output VAT and 1,755 owed.
     * Only someone who manages billing downloads the books.
     */
    public function test_journal_and_trial_balance_balance(): void
    {
        $balance = app(ExpenseReports::class)->trialBalance($this->from, $this->to);
        $by = array_column($balance['rows'], 'balance', 'account');

        $this->assertSame($balance['debit'], $balance['credit']);
        $this->assertSame(['1150.00', '-1000.00', '-206.25', '206.25', '1605.00', '-1755.00'], [
            $by['receivables'], $by['revenue'], $by['vat_output'], $by['vat_input'], $by['expenses'], $by['payables'],
        ]);

        $admin = fn (string $role): User => tap(User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]), fn (User $user) => $user->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save());
        $query = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $this->actingAs($admin('support'))->get(route('admin.finance.books.csv', ['book' => 'journal', ...$query]))->assertForbidden();
        $operations = $admin('operations');

        foreach (['journal' => 'Accounts receivable', 'trial-balance' => 'VAT output', 'cash' => 'Bank account'] as $book => $expected) {
            $this->assertStringContainsString($expected, $this->actingAs($operations)->get(route('admin.finance.books.csv', ['book' => $book, ...$query]))->assertOk()->streamedContent());
        }
    }

    /**
     * Relief on the platform's own PLT-1 (1,000 + 150), counted in October:
     * the VAT view shows the 150 taken back, the P&L takes it as a gain.
     */
    public function test_platform_relief_has_its_own_line(): void
    {
        $invoice = Invoice::acrossTenants()->where('invoice_number', 'PLT-1')->sole();
        BadDebtRelief::acrossTenants()->create([
            'org_id' => $invoice->org_id, 'invoice_id' => $invoice->id, 'kind' => BadDebtRelief::RELIEF, 'happened_on' => '2026-10-15',
            'amount' => (string) $invoice->total, 'written_off_on' => '2026-10-15', 'certificate_reference' => 'CPA-1', 'unrelated_confirmed' => true,
        ]);
        $reports = app(ExpenseReports::class);

        $this->assertSame('-150.00', $reports->vat($this->from, $this->to)['summary']['bad_debt_relief']);
        $this->assertSame('150.00', $reports->profitAndLoss($this->from, $this->to)['totals']['vat_relief']);
    }

    /**
     * O-4 (10,350) was voided today: as at 10 October it was still owed,
     * beside O-1 and O-2 (1,150 + 230); as at today it is not.
     */
    public function test_bill_voided_later_is_still_aged(): void
    {
        $reports = app(ExpenseReports::class);

        $this->assertSame('11730.00', $reports->payables(CarbonImmutable::parse('2026-10-10'))['SAR']['totals']['total']);
        $this->assertSame('1380.00', $reports->payables(CarbonImmutable::parse('2026-10-20'))['SAR']['totals']['total']);
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
        AuthorityAnswer::record(Invoice::acrossTenants()->whereKey($receivable->invoice_id)->sole(), 'accepted', ['invoice_number' => $number, 'hash' => base64_encode(hash('sha256', $number, true))]);

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
