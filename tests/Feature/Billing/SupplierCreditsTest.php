<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ExpenseEntry;
use App\Domains\Billing\DTOs\OutgoingPayment;
use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Enums\OutgoingChannel;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Models\SupplierPayment;
use App\Domains\Billing\Services\ExpenseReports;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\Payables;
use App\Domains\Billing\Services\SupplierCredits;
use App\Domains\Billing\Services\SupplierPayments;
use App\Domains\Billing\Services\Suppliers;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A supplier's credit note takes off what is owed and the input VAT the
 * bill deducted, in the month it is dated; a payment recorded in error is
 * reversed by a second row below zero, once - both in the payables, the
 * VAT return draft and the profit and loss.
 */
class SupplierCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $office;

    private Supplier $hosting;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));
        $this->from = CarbonImmutable::parse('2026-10-01');
        $this->to = CarbonImmutable::parse('2026-10-31');
        $suppliers = app(Suppliers::class);
        $this->office = $suppliers->save(null, ['name' => 'Riyadh Office Supplies', 'country' => 'SA', 'currency' => 'SAR', 'payment_terms_days' => 30, 'category' => 'office'], null);
        $this->hosting = $suppliers->save(null, ['name' => 'Cloud Hosting Ltd', 'country' => 'IE', 'currency' => 'USD', 'payment_terms_days' => 0, 'category' => 'hosting'], null);
    }

    /**
     * A 1000 + 150 bill, then a credit note of 200 + 30 on 12 October:
     * 920 owed, 800 bought and 120 input VAT deducted in October, a cost
     * of 800; at 11 October still 1150 owed.
     */
    public function test_credit_note_reduces_bill_and_vat(): void
    {
        $bill = $this->bill($this->office, '1000', '150', VatTreatment::Recoverable);

        $note = app(SupplierCredits::class)->credit($bill, '200', '30', CarbonImmutable::parse('2026-10-12'), 'CN-77', 'Returned two chairs', null);

        $this->assertSame(['230.00', '30.00'], [(string) $note->gross, (string) $note->recoverable_vat_tax]);
        $this->assertSame(['920.00', '230.00'], [$bill->fresh()->balance(), (string) $bill->fresh()->amount_credited]);

        $vat = app(ExpenseReports::class)->vat($this->from, $this->to)['summary'];
        $this->assertSame(['800.00', '120.00', '120.00'], [$vat['purchases_net'], $vat['input_charged'], $vat['input_recoverable']]);
        $this->assertContains('CN-77', array_column(app(ExpenseReports::class)->vat($this->from, $this->to)['expenses'], 'number'));
        $this->assertSame('800.00', app(ExpenseReports::class)->profitAndLoss($this->from, $this->to)['totals']['costs']);

        $payables = app(Payables::class);
        $this->assertSame('1150.00', $payables->ageing(CarbonImmutable::parse('2026-10-11'))['SAR']['totals']['total']);
        $this->assertSame('920.00', $payables->ageing(CarbonImmutable::parse('2026-10-12'))['SAR']['totals']['total']);
    }

    /**
     * Reverse charge: 100 USD at 3.75 self-assesses 56.25 each side; 40
     * USD credited at the bill's rate takes back 150 SAR and 22.50 each
     * side, leaving 225, 33.75 and 33.75.
     */
    public function test_reverse_charge_credit_both_sides(): void
    {
        $bill = $this->bill($this->hosting, '100', '0', VatTreatment::ReverseCharge, 'USD', '3.75');

        app(SupplierCredits::class)->credit($bill, '40', '0', CarbonImmutable::parse('2026-10-15'), 'C-1', 'Downtime credit', null);

        $vat = app(ExpenseReports::class)->vat($this->from, $this->to)['summary'];
        $this->assertSame(['225.00', '33.75', '33.75', '0.00'], [$vat['reverse_charge_base'], $vat['reverse_charge_output'], $vat['reverse_charge_input'], $vat['net_payable']]);
    }

    public function test_no_more_than_billed_is_credited(): void
    {
        $bill = $this->bill($this->office, '100', '15', VatTreatment::Recoverable);

        $this->expectException(DomainException::class);
        app(SupplierCredits::class)->credit($bill, '100', '15.01', CarbonImmutable::parse('2026-10-12'), null, 'Too much', null);
    }

    /**
     * 500 paid on 15 October in error, reversed on 20 October: owed 1150
     * again from that day, the payment answered by -500, never twice.
     */
    public function test_a_payment_is_reversed_once(): void
    {
        $bill = $this->bill($this->office, '1000', '150', VatTreatment::Recoverable);
        $paid = app(SupplierPayments::class)->pay($bill, new OutgoingPayment('500', CarbonImmutable::parse('2026-10-15'), OutgoingChannel::Bank, 'TRF-5'), null);

        $reversal = app(SupplierPayments::class)->reverse($paid, 'Paid the wrong supplier', null);

        $this->assertSame(['-500.00', (string) $paid->id], [(string) $reversal->amount, (string) $reversal->reverses_id]);
        $this->assertSame([ExpenseStatus::Unpaid, '1150.00'], [$bill->fresh()->status, $bill->fresh()->balance()]);
        $payables = app(Payables::class);
        $this->assertSame('650.00', $payables->ageing(CarbonImmutable::parse('2026-10-16'))['SAR']['totals']['total']);
        $this->assertSame('1150.00', $payables->ageing(CarbonImmutable::parse('2026-10-20'))['SAR']['totals']['total']);

        try {
            app(SupplierPayments::class)->reverse($paid->fresh(), 'Again', null);
            $this->fail('A payment was reversed twice.');
        } catch (DomainException) {
        }

        $this->assertSame(2, SupplierPayment::query()->count());
    }

    /**
     * 1000 USD booked at 3.75; 400 paid at 3.76 loses 4.00. Reversed, the
     * loss goes with it: nothing realised in October.
     */
    public function test_reversal_takes_back_its_exchange(): void
    {
        $bill = $this->bill($this->hosting, '1000', '0', VatTreatment::ReverseCharge, 'USD', '3.75');
        $paid = app(SupplierPayments::class)->pay($bill, new OutgoingPayment('400', CarbonImmutable::parse('2026-10-15'), OutgoingChannel::Bank, exchangeRate: '3.76'), null);
        $this->assertSame('-4.00', (string) $paid->exchange_difference);

        app(SupplierPayments::class)->reverse($paid, 'Duplicate', null);

        $this->assertSame('0.00', (string) $bill->fresh()->exchange_difference);
        $this->assertSame('0.00', app(ExpenseReports::class)->profitAndLoss($this->from, $this->to)['totals']['exchange']);
    }

    public function test_admins_credit_and_reverse_from_page(): void
    {
        $operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $operations->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $bill = $this->bill($this->office, '1000', '150', VatTreatment::Recoverable);
        $paid = app(SupplierPayments::class)->pay($bill, new OutgoingPayment('100', CarbonImmutable::parse('2026-10-15'), OutgoingChannel::Bank, 'TRF-1'), null);

        $this->actingAs($operations)->post(route('admin.finance.expenses.credit-notes.store', $bill->id), [
            'net' => '200', 'vat' => '30', 'credit_date' => '2026-10-18', 'supplier_credit_number' => 'CN-9', 'reason' => 'Returned goods',
        ])->assertRedirect(route('admin.finance.expenses.show', $bill->id))->assertSessionHas('success');
        $this->actingAs($operations)->post(route('admin.finance.expenses.payments.reverse', [$bill->id, $paid->id]), ['reason' => 'Wrong supplier'])
            ->assertRedirect(route('admin.finance.expenses.show', $bill->id))->assertSessionHas('success');

        $this->assertSame('920.00', $bill->fresh()->balance());
        $this->actingAs($operations)->get(route('admin.finance.expenses.show', $bill->id))->assertOk()->assertSee('CN-9')->assertSee('-100.00');
    }

    private function bill(Supplier $supplier, string $net, string $vat, VatTreatment $treatment, string $currency = 'SAR', ?string $rate = null): Expense
    {
        return app(Expenses::class)->record(new ExpenseEntry(
            supplierId: (string) $supplier->id,
            expenseDate: CarbonImmutable::parse('2026-10-05'),
            description: 'A bill',
            category: $supplier->category,
            currency: $currency,
            net: $net,
            vat: $vat,
            vatTreatment: $treatment,
            supplierInvoiceNumber: 'B-'.$net,
            exchangeRate: $rate,
        ), null, false, null);
    }
}
