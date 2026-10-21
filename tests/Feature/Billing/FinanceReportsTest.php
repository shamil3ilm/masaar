<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\DTOs\ReceivedPayment;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Services\FinanceReports;
use App\Domains\Billing\Services\Payments;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\Refunds;
use App\Domains\Billing\Settings\FinanceSettings;
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
 * Admin › Finance › Reports: receivables, payments by method and currency,
 * VAT on the platform's own invoices in riyals, collections and ageing -
 * on the page and as CSVs.
 */
class FinanceReportsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $gulf;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));
        $this->from = CarbonImmutable::parse('2026-10-01');
        $this->to = CarbonImmutable::parse('2026-10-31');

        $platform = Organization::create([
            'name' => Brand::name('en').' Platform', 'name_ar' => 'منصة '.Brand::name('ar'), 'country' => 'SA',
            'vat_number' => '300000000000003', 'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        config(['billing.organization_id' => $platform->id]);
        $this->mock(InvoiceIssuer::class, fn ($mock) => $mock->shouldReceive('issue')->andReturn(IssueOutcome::issuedOnly()));

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'vat_number' => '310122393500003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $this->gulf = Organization::create(['name' => 'Gulf Trading LLC', 'name_ar' => 'الخليج للتجارة', 'country' => 'AE', 'vat_number' => '100000000000003', 'city' => 'Dubai']);

        $settings = app(FinanceSettings::class);
        $settings->setCurrencies(['SAR', 'AED'], 'SAR', null);
        $settings->set(FinanceSettings::CURRENCY, 'AED', null, (string) $this->gulf->id);
        // A dirham payment needs its day's rate.
        app(ExchangeRates::class)->record('AED', CarbonImmutable::parse('2026-10-01'), '1.02', null);

        // 1150 SAR to Acme, paid by transfer less a 10 SAR fee, then 100
        // credited back; 1150 AED to Gulf at 1.02, 500 of it paid.
        $sar = $this->invoice($this->acme, '1000');
        $this->transfer($this->acme, '1150', 'SAR', '10');
        $refund = app(Refunds::class)->refund($sar, '100', 'Discount agreed', 'خصم متفق عليه', Refund::TO_CREDIT, null, null);
        $this->seal((string) $refund->credit_note_id, 'PLT-CN-1');
        $this->invoice($this->gulf, '1000', '1.02');
        $this->transfer($this->gulf, '500', 'AED');
    }

    public function test_receivables_by_currency(): void
    {
        $report = app(FinanceReports::class)->receivables($this->from, $this->to);

        $this->assertSame(['count' => 1, 'net' => '1000.00', 'vat' => '150.00', 'total' => '1150.00', 'paid' => '1150.00', 'by_credit' => '0.00', 'credited' => '115.00', 'written_off' => '0.00', 'outstanding' => '0.00'], $report['SAR']);
        $this->assertSame('650.00', $report['AED']['outstanding']);
    }

    /**
     * 1150 SAR came in once. The credit note's 115 left over is credit; a
     * second 1150 invoice takes it. Paid stays the 1150 received, the 115
     * shows as settled by credit, and 1035 is still owed.
     */
    public function test_reused_credit_is_not_cash(): void
    {
        $this->invoice($this->acme, '1000');

        $report = app(FinanceReports::class)->receivables($this->from, $this->to);

        $this->assertSame(['2300.00', '1150.00', '115.00', '115.00', '1035.00'], [
            $report['SAR']['total'], $report['SAR']['paid'], $report['SAR']['by_credit'], $report['SAR']['credited'], $report['SAR']['outstanding'],
        ]);
    }

    /**
     * October's receivables are as they stood on 31 October: Gulf's 650
     * AED still owed, though it was paid on 10 November.
     */
    public function test_receivables_stand_at_period_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC'));
        $this->transfer($this->gulf, '650', 'AED');

        $october = app(FinanceReports::class)->receivables($this->from, $this->to)['AED'];
        $this->assertSame(['500.00', '650.00'], [$october['paid'], $october['outstanding']]);

        $today = app(FinanceReports::class)->receivables($this->from, CarbonImmutable::parse('2026-11-10'))['AED'];
        $this->assertSame(['1150.00', '0.00'], [$today['paid'], $today['outstanding']]);
    }

    public function test_payments_by_method_and_currency(): void
    {
        $report = collect(app(FinanceReports::class)->payments($this->from, $this->to))->keyBy('currency');

        $this->assertSame(['1', '1150.00', '10.00', '1140.00'], [(string) $report['SAR']['count'], $report['SAR']['received'], $report['SAR']['fees'], $report['SAR']['net']]);
        $this->assertSame('500.00', $report['AED']['received']);
        $this->assertSame('Bank transfer', $report['AED']['method']);
    }

    /** 150 SAR, 150 AED at 1.02 = 153 SAR, less the note's 15 SAR: 288 SAR. */
    public function test_vat_is_stated_in_riyals(): void
    {
        $report = app(FinanceReports::class)->vat($this->from, $this->to);

        $this->assertSame('SAR', $report['tax_currency']);
        $this->assertSame(['150.00', '153.00', '-15.00'], array_column($report['documents'], 'vat_tax'));
        $this->assertSame('288.00', $report['totals']['vat']);
        $this->assertSame('1.02', $report['documents'][1]['rate']);
    }

    /**
     * A cleared invoice ZATCA refused keeps its hash but is no tax
     * document: its 500 net and 75 VAT are left out, so the VAT stays
     * 288 SAR and the net 1000 + 1020 (1000 AED at 1.02) - 100 = 1920 SAR.
     */
    public function test_rejected_documents_are_not_declared(): void
    {
        $rejected = $this->invoice($this->acme, '500');
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($rejected->invoice_id)->update(['status' => 'rejected']));

        $report = app(FinanceReports::class)->vat($this->from, $this->to);

        $this->assertSame('288.00', $report['totals']['vat']);
        $this->assertSame('1920.00', $report['totals']['net']);
        $this->assertNotContains((string) Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($rejected->invoice_id)->value('invoice_number')), array_column($report['documents'], 'number'));
    }

    public function test_collections_and_ageing(): void
    {
        $reports = app(FinanceReports::class);
        $collections = $reports->collections($this->from, CarbonImmutable::parse('2026-11-30'));

        $this->assertSame('1035.00', $collections['SAR']['fell_due']);
        $this->assertSame('1150.00', $collections['SAR']['collected']);
        $this->assertSame('0.0', $collections['SAR']['average_days_to_pay']);
        $this->assertSame('650.00', $collections['AED']['overdue']);
        $this->assertSame(1, $collections['AED']['overdue_count']);

        $this->assertSame('650.00', $reports->ageing(CarbonImmutable::parse('2026-11-15'))['AED']['totals']['days_30']);
    }

    public function test_the_page_and_its_csvs(): void
    {
        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $support->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $period = ['from' => '2026-10-01', 'to' => '2026-10-31'];

        $this->actingAs($support)->get(route('admin.finance.reports', $period))
            ->assertOk()->assertSee('PLT-CN-1')->assertSee('288.00');

        foreach (['receivables' => '1150.00', 'payments' => '1140.00', 'vat' => '-15.00', 'collections' => '1035.00', 'ageing' => 'Gulf Trading LLC'] as $report => $expected) {
            $csv = $this->actingAs($support)->get(route('admin.finance.reports.csv', ['report' => $report, ...$period]))->assertOk()->streamedContent();
            $this->assertStringContainsString($expected, $csv, $report.' CSV');
        }
    }

    private function invoice(Organization $customer, string $amount, ?string $rate = null): Receivable
    {
        $receivable = app(Receivables::class)->issue($customer, new ReceivableDraft('Subscription', 'اشتراك', $amount, exchangeRate: $rate), null);
        $this->seal((string) $receivable->invoice_id, 'PLT-'.Invoice::withoutTenantScope(fn () => Invoice::query()->whereNotNull('hash')->count()));

        return $receivable;
    }

    /** What a real issue gives: a number and a hash. */
    private function seal(string $invoiceId, string $number): void
    {
        AuthorityAnswer::record(Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoiceId)->sole()), 'accepted', ['invoice_number' => $number, 'hash' => base64_encode(hash('sha256', $number, true))]);
    }

    private function transfer(Organization $customer, string $amount, string $currency, ?string $fee = null): void
    {
        app(Payments::class)->recordTransfer($customer, new ReceivedPayment($amount, $currency, CarbonImmutable::now(), 'TRF-'.$currency, fee: $fee), null);
    }
}
