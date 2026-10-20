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
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\Payables;
use App\Domains\Billing\Services\SupplierPayments;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Platform\Approvals\Actions\ExpenseActions;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Models\ApprovalRequest;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paying suppliers: part payments down to paid, each with its channel and
 * statement reference; a large payment and a write-off under their
 * approval policies; and what is owed by age.
 */
class SupplierPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $operations;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));
        $this->operations = $this->admin('operations');
        $this->owner = $this->admin('owner');
        $this->supplier = app(Suppliers::class)->save(null, ['name' => 'Gateway Co', 'country' => 'SA', 'currency' => 'SAR', 'payment_terms_days' => 30, 'category' => 'payment_provider'], null);
    }

    public function test_part_payments_settle_the_expense(): void
    {
        $expense = $this->expense('1000', '150');

        $this->actingAs($this->operations)->post(route('admin.finance.expenses.pay', $expense->id), [
            'amount' => '500', 'paid_on' => '2026-10-15', 'channel' => 'bank', 'reference' => 'TRF-881', 'paid_from' => 'Main account',
        ])->assertRedirect(route('admin.finance.expenses.show', $expense->id));

        $this->assertSame(ExpenseStatus::PartlyPaid, $expense->fresh()->status);
        $this->assertSame('650.00', $expense->fresh()->balance());

        $payment = SupplierPayment::query()->sole();
        $this->assertSame([OutgoingChannel::Bank, 'TRF-881', 'Main account', '500.00'], [$payment->channel, $payment->reference, $payment->paid_from, (string) $payment->amount_tax]);
        $this->assertSame((string) $this->operations->id, (string) $payment->created_by);

        app(SupplierPayments::class)->pay($expense, new OutgoingPayment('650', CarbonImmutable::parse('2026-10-18'), OutgoingChannel::Card, 'CARD-4421'), null);

        $paid = $expense->fresh();
        $this->assertSame(ExpenseStatus::Paid, $paid->status);
        $this->assertSame('2026-10-18', $paid->paid_on->toDateString());
        $this->assertFalse($paid->editable());
    }

    public function test_more_than_owed_is_refused(): void
    {
        $expense = $this->expense('100', '15');

        $this->expectException(DomainException::class);
        app(SupplierPayments::class)->pay($expense, new OutgoingPayment('115.01', CarbonImmutable::parse('2026-10-15'), OutgoingChannel::Bank), null);
    }

    public function test_a_large_payment_waits_for_approval(): void
    {
        $this->policy(ExpenseActions::PAY);
        $expense = $this->expense('20000', '3000');

        $this->actingAs($this->operations)->post(route('admin.finance.expenses.pay', $expense->id), [
            'amount' => '23000', 'paid_on' => '2026-10-15', 'channel' => 'bank', 'reference' => 'TRF-900', 'approval_reason' => 'Quarterly fee',
        ])->assertRedirect();

        $this->assertSame(0, SupplierPayment::query()->count());
        $request = ApprovalRequest::query()->where('action', ExpenseActions::PAY)->sole();

        $this->actingAs($this->owner)->post(route('admin.approvals.approve', $request->id))->assertRedirect();

        $this->assertSame(ExpenseStatus::Paid, $expense->fresh()->status);
        $this->assertSame((string) $this->operations->id, (string) SupplierPayment::query()->sole()->created_by);
    }

    public function test_a_small_payment_is_never_held(): void
    {
        $this->policy(ExpenseActions::PAY);
        $expense = $this->expense('1000', '150');

        $this->actingAs($this->operations)->post(route('admin.finance.expenses.pay', $expense->id), [
            'amount' => '1150', 'paid_on' => '2026-10-15', 'channel' => 'card',
        ])->assertRedirect(route('admin.finance.expenses.show', $expense->id));

        $this->assertSame(ExpenseStatus::Paid, $expense->fresh()->status);
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_a_write_off_waits_for_approval(): void
    {
        $this->policy(ExpenseActions::WRITE_OFF);
        $expense = $this->expense('1000', '150');
        app(SupplierPayments::class)->pay($expense, new OutgoingPayment('150', CarbonImmutable::parse('2026-10-15'), OutgoingChannel::Bank), null);

        $this->actingAs($this->operations)->post(route('admin.finance.expenses.write-off', $expense->id), ['reason' => 'Supplier waived the rest'])->assertRedirect();
        $this->assertSame(ExpenseStatus::PartlyPaid, $expense->fresh()->status);

        $this->actingAs($this->owner)->post(route('admin.approvals.approve', ApprovalRequest::query()->sole()->id))->assertRedirect();

        $written = $expense->fresh();
        $this->assertSame(ExpenseStatus::WrittenOff, $written->status);
        $this->assertSame(['1000.00', '0.00'], [(string) $written->amount_written_off, $written->balance()]);
        $this->assertSame((string) $this->operations->id, (string) $written->written_off_by);
    }

    public function test_payables_are_aged_by_supplier(): void
    {
        $this->expense('1000', '150', '2026-08-01');
        $this->expense('200', '30', '2026-10-10', 'B-2');

        $ageing = app(Payables::class)->ageing(CarbonImmutable::parse('2026-10-20'));

        // Due 2026-08-31: 50 days late. Due 2026-11-09: not yet.
        $this->assertSame(['1150.00', '230.00', '1380.00'], [$ageing['SAR']['totals']['days_60'], $ageing['SAR']['totals']['current'], $ageing['SAR']['totals']['total']]);

        $this->actingAs($this->operations)->get(route('admin.finance.payables', ['overdue' => 'overdue']))->assertOk()->assertSee('1,150.00')->assertDontSee('B-2');
        $csv = $this->actingAs($this->operations)->get(route('admin.finance.payables.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('B-2', $csv);
    }

    private function expense(string $net, string $vat, string $date = '2026-10-10', string $number = 'B-1'): Expense
    {
        return app(Expenses::class)->record(new ExpenseEntry(
            supplierId: (string) $this->supplier->id,
            expenseDate: CarbonImmutable::parse($date),
            description: 'Gateway fees',
            category: 'payment_provider',
            currency: 'SAR',
            net: $net,
            vat: $vat,
            vatTreatment: VatTreatment::Recoverable,
            supplierInvoiceNumber: $number,
        ), null, false, null);
    }

    private function policy(string $action): void
    {
        app(ApprovalPolicies::class)->save($action, ['enabled' => true, 'approvals_required' => 1, 'approver_roles' => ['owner'], 'expires_after_hours' => 72], null);
    }

    private function admin(string $role): User
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
        $admin->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        return $admin;
    }
}
