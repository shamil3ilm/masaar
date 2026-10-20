<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ExpenseEntry;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Billing\Settings\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Reverse charge on services from a supplier abroad (VAT Implementing
 * Regulations Art 47(1)): the platform assesses the VAT itself at the
 * standard rate, as output VAT, and deducts it as input VAT - both kept on
 * the expense, in riyals, for the VAT return.
 */
class ReverseChargeTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $abroad;

    private Supplier $home;

    protected function setUp(): void
    {
        parent::setUp();

        $suppliers = app(Suppliers::class);
        $this->abroad = $suppliers->save(null, ['name' => 'Cloud Hosting Ltd', 'country' => 'IE', 'currency' => 'USD', 'payment_terms_days' => 0, 'category' => 'hosting'], null);
        $this->home = $suppliers->save(null, ['name' => 'Riyadh Hosting', 'country' => 'SA', 'currency' => 'SAR', 'payment_terms_days' => 30, 'category' => 'hosting'], null);
    }

    /** 100 USD at 3.75 is 375 SAR; 15% of it is 56.25 out and 56.25 in. */
    public function test_hosting_abroad_is_self_assessed(): void
    {
        $expense = $this->record($this->abroad);

        $this->assertSame('375.00', (string) $expense->net_tax);
        $this->assertSame('0.00', (string) $expense->vat_tax);
        $this->assertSame('15.00', (string) $expense->vat_rate);
        $this->assertSame('56.25', (string) $expense->self_assessed_vat_tax);
        $this->assertSame('56.25', (string) $expense->recoverable_vat_tax);
        // What is owed to the supplier is what it billed: no VAT on top.
        $this->assertSame('100.00', (string) $expense->gross);
    }

    public function test_resident_supplier_is_not_reverse_charge(): void
    {
        try {
            $this->record($this->home, currency: 'SAR', rate: null);
            $this->fail('A supplier at home was taken as reverse charge.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vat_treatment', $e->errors());
        }

        $this->assertSame(VatTreatment::ReverseCharge, app(Expenses::class)->treatmentFor($this->abroad));
        $this->assertSame(VatTreatment::Recoverable, app(Expenses::class)->treatmentFor($this->home));
    }

    public function test_deduction_follows_the_recovery_setting(): void
    {
        app(FinanceSettings::class)->set(FinanceSettings::INPUT_VAT_RECOVERY, 50, null);

        $expense = $this->record($this->abroad);

        $this->assertSame('56.25', (string) $expense->self_assessed_vat_tax);
        $this->assertSame('28.13', (string) $expense->recoverable_vat_tax);
    }

    public function test_an_unregistered_platform_assesses_nothing(): void
    {
        app(FinanceSettings::class)->set(FinanceSettings::VAT_REGISTERED, false, null);

        $expense = $this->record($this->abroad);

        $this->assertNull($expense->vat_rate);
        $this->assertSame(['0.00', '0.00'], [(string) $expense->self_assessed_vat_tax, (string) $expense->recoverable_vat_tax]);
    }

    public function test_the_settings_page_saves_both(): void
    {
        $owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
        $owner->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($owner)->post(route('admin.finance.settings.purchase-vat'), ['vat_registered' => '0', 'input_vat_recovery_percent' => '80'])->assertRedirect();

        $settings = app(FinanceSettings::class);
        $this->assertFalse($settings->vatRegistered());
        $this->assertSame(80, $settings->inputVatRecovery());
        $this->actingAs($owner)->get(route('admin.finance.expenses.create', ['supplier' => $this->abroad->id]))
            ->assertOk()->assertSee('value="reverse_charge" selected', false);
    }

    private function record(Supplier $supplier, string $currency = 'USD', ?string $rate = '3.75'): Expense
    {
        return app(Expenses::class)->record(new ExpenseEntry(
            supplierId: (string) $supplier->id,
            expenseDate: CarbonImmutable::parse('2026-10-01'),
            description: 'Servers, October',
            category: 'hosting',
            currency: $currency,
            net: '100',
            vat: '0',
            vatTreatment: VatTreatment::ReverseCharge,
            exchangeRate: $rate,
        ), null, false, null);
    }
}
