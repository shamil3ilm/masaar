<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\RecurringExpense;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\RecurringExpenses;
use App\Domains\Billing\Services\Suppliers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recurring costs: each bill drafted on the day it is expected, missed ones
 * caught up, and one still not entered after the set days reported missing
 * - once - on the console's page and menu.
 */
class RecurringExpensesTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'));
        $this->provider = app(Suppliers::class)->save(null, ['name' => 'E-invoicing Provider LLC', 'country' => 'SA', 'currency' => 'SAR', 'payment_terms_days' => 15, 'category' => 'e_invoicing_provider'], null);
    }

    public function test_a_due_bill_is_drafted_once(): void
    {
        $recurring = $this->recurring('2026-10-05');
        $service = app(RecurringExpenses::class);

        $this->assertSame(1, $service->run(CarbonImmutable::parse('2026-10-05'))['drafted']);
        $this->assertSame(0, $service->run(CarbonImmutable::parse('2026-10-05'))['drafted']);

        $draft = Expense::query()->sole();
        $this->assertSame(ExpenseStatus::Draft, $draft->status);
        $this->assertSame(['2026-10-05', '2026-10-20', '1150.00'], [$draft->expense_date->toDateString(), $draft->due_date->toDateString(), (string) $draft->gross]);
        $this->assertSame((string) $recurring->id, (string) $draft->recurring_expense_id);
        $this->assertSame('2026-11-05', $recurring->fresh()->next_due_on->toDateString());
        $this->assertSame('system', $draft->created_via);
    }

    public function test_missed_months_are_caught_up(): void
    {
        $this->recurring('2026-08-05');

        $this->assertSame(3, app(RecurringExpenses::class)->run(CarbonImmutable::parse('2026-10-05'))['drafted']);
        $this->assertSame(['2026-08-05', '2026-09-05', '2026-10-05'], Expense::query()->orderBy('expense_date')->get()->map(fn (Expense $expense) => $expense->expense_date->toDateString())->all());
    }

    public function test_a_late_bill_is_reported_once(): void
    {
        $this->recurring('2026-10-05');
        $service = app(RecurringExpenses::class);
        $service->run(CarbonImmutable::parse('2026-10-05'));

        $this->assertSame(0, $service->run(CarbonImmutable::parse('2026-10-11'))['missing']);
        $this->assertSame(1, $service->run(CarbonImmutable::parse('2026-10-12'))['missing']);
        $this->assertSame(1, $service->run(CarbonImmutable::parse('2026-10-13'))['missing']);
        $this->assertSame(1, AuditLog::query()->where('action', 'finance.expense.missing')->count());

        // Recorded, it is no longer missing.
        app(Expenses::class)->confirm(Expense::query()->sole(), null);
        $this->assertSame(0, $service->missingCount(CarbonImmutable::parse('2026-10-13')));
    }

    public function test_a_paused_cost_drafts_nothing(): void
    {
        $this->recurring('2026-10-05')->forceFill(['active' => false])->save();

        $this->assertSame(0, app(RecurringExpenses::class)->run(CarbonImmutable::parse('2026-10-05'))['drafted']);
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_no_rate_leaves_the_bill_due(): void
    {
        $recurring = $this->recurring('2026-10-05', ['currency' => 'USD']);

        $outcome = app(RecurringExpenses::class)->run(CarbonImmutable::parse('2026-10-05'));

        $this->assertSame(0, $outcome['drafted']);
        $this->assertCount(1, $outcome['failed']);
        $this->assertSame('2026-10-05', $recurring->fresh()->next_due_on->toDateString());
    }

    public function test_the_command_page_and_menu(): void
    {
        $this->recurring('2026-09-20');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'));

        $this->artisan('billing:expect-expenses')->expectsOutputToContain('Drafted 1 expected bills; 1 missing.')->assertSuccessful();

        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $admin->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin)->get(route('admin.finance.recurring'))
            ->assertOk()->assertSee('data-missing-bills', false)->assertSee('data-missing-bills-count', false)->assertSee('2026-09-20');

        $this->actingAs($admin)->post(route('admin.finance.recurring.store'), [
            'supplier_id' => $this->provider->id, 'description' => 'Yearly licence', 'category' => 'software', 'currency' => 'SAR',
            'net' => '5000', 'vat' => '750', 'vat_treatment' => 'recoverable', 'frequency' => 'yearly', 'next_due_on' => '2027-01-01', 'active' => '1',
        ])->assertRedirect();
        $this->assertSame('yearly', RecurringExpense::query()->where('description', 'Yearly licence')->sole()->frequency);
    }

    /** @param  array<string, mixed>  $overrides */
    private function recurring(string $next, array $overrides = []): RecurringExpense
    {
        return app(RecurringExpenses::class)->save(null, [
            'supplier_id' => $this->provider->id, 'description' => 'Monthly provider fee', 'category' => 'e_invoicing_provider',
            'cost_centre' => 'platform', 'currency' => 'SAR', 'net' => '1000', 'vat' => '150', 'vat_treatment' => 'recoverable',
            'frequency' => RecurringExpense::MONTHLY, 'next_due_on' => $next, 'active' => true, ...$overrides,
        ], null);
    }
}
