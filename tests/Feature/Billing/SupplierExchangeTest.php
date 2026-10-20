<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ExpenseEntry;
use App\Domains\Billing\DTOs\OutgoingPayment;
use App\Domains\Billing\Enums\OutgoingChannel;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\SupplierPayment;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Services\ExpenseReports;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\SupplierPayments;
use App\Domains\Billing\Services\Suppliers;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A bill in dollars booked at 3.75 and paid at another day's rate: each
 * payment in riyals at its own rate, and the realised exchange gain or
 * loss on the part it settles - pro rata for a part payment - kept on the
 * payment and the expense, and shown in profit and loss.
 */
class SupplierExchangeTest extends TestCase
{
    use RefreshDatabase;

    private Expense $bill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));
        $supplier = app(Suppliers::class)->save(null, ['name' => 'Cloud Hosting Ltd', 'country' => 'IE', 'currency' => 'USD', 'payment_terms_days' => 30, 'category' => 'hosting'], null);

        // 100 USD at 3.75: 375.00 SAR owed as booked.
        $this->bill = app(Expenses::class)->record(new ExpenseEntry(
            supplierId: (string) $supplier->id,
            expenseDate: CarbonImmutable::parse('2026-10-01'),
            description: 'Servers, October',
            category: 'hosting',
            currency: 'USD',
            net: '100',
            vat: '0',
            vatTreatment: VatTreatment::NoVat,
            supplierInvoiceNumber: 'H-10',
            exchangeRate: '3.75',
        ), null, false, null);
    }

    public function test_a_higher_rate_is_a_loss(): void
    {
        $paid = $this->pay('100', '3.80');

        $this->assertSame(['380.00', '375.00', '-5.00'], [(string) $paid->amount_tax, (string) $paid->settled_tax, (string) $paid->exchange_difference]);
        $this->assertSame('-5.00', (string) $this->bill->fresh()->exchange_difference);
    }

    public function test_a_lower_rate_is_a_gain(): void
    {
        app(ExchangeRates::class)->record('USD', CarbonImmutable::parse('2026-10-15'), '3.70', null);

        $paid = $this->pay('100', null);

        $this->assertSame(['3.700000', 'table', '370.00', '5.00'], [(string) $paid->exchange_rate, $paid->rate_source, (string) $paid->amount_tax, (string) $paid->exchange_difference]);
        $this->assertSame('5.00', (string) $this->bill->fresh()->exchange_difference);
    }

    /** 40 at 3.80 settles 150.00 for 152.00; 60 at 3.70 settles the 225.00 left for 222.00. */
    public function test_two_parts_settle_pro_rata(): void
    {
        $first = $this->pay('40', '3.80');
        $second = $this->pay('60', '3.70');

        $this->assertSame(['150.00', '-2.00'], [(string) $first->settled_tax, (string) $first->exchange_difference]);
        $this->assertSame(['225.00', '3.00'], [(string) $second->settled_tax, (string) $second->exchange_difference]);
        $this->assertSame('1.00', (string) $this->bill->fresh()->exchange_difference);
    }

    public function test_a_day_without_rate_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->pay('100', null);
    }

    public function test_shown_in_pnl_and_pages(): void
    {
        $this->pay('100', '3.80');

        $pnl = app(ExpenseReports::class)->profitAndLoss(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $this->assertSame(['375.00', '-5.00', '-380.00'], [$pnl['totals']['costs'], $pnl['totals']['exchange'], $pnl['totals']['profit']]);

        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $admin->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin)->get(route('admin.finance.expenses.show', $this->bill->id))->assertOk()->assertSee('data-exchange-difference', false)->assertSee('-5.00');
        $csv = $this->actingAs($admin)->get(route('admin.finance.expense-reports.csv', ['report' => 'pnl', 'from' => '2026-10-01', 'to' => '2026-10-31']))->streamedContent();
        $this->assertStringContainsString('Exchange differences', $csv);
        $this->assertStringContainsString('-5.00', $csv);
        $csv = $this->actingAs($admin)->get(route('admin.finance.expenses.export'))->streamedContent();
        $this->assertStringContainsString('Exchange difference', $csv);
    }

    private function pay(string $amount, ?string $rate): SupplierPayment
    {
        return app(SupplierPayments::class)->pay($this->bill->fresh(), new OutgoingPayment($amount, CarbonImmutable::parse('2026-10-15'), OutgoingChannel::Bank, exchangeRate: $rate), null);
    }
}
