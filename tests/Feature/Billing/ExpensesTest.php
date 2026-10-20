<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ExpenseEntry;
use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Services\ExpenseReceipts;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\Suppliers;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Admin â€º Finance â€º Expenses: in the currency billed and in riyals at the
 * day's rate, with their VAT treatment, a private receipt, drafts, void
 * ones, and the same supplier invoice refused twice.
 */
class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $operations;

    private Supplier $hosting;

    private Supplier $local;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC'));
        Storage::fake(ExpenseReceipts::DISK);
        $this->operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $this->operations->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        $suppliers = app(Suppliers::class);
        $this->hosting = $suppliers->save(null, ['name' => 'Cloud Hosting Ltd', 'country' => 'IE', 'currency' => 'USD', 'payment_terms_days' => 14, 'category' => 'hosting'], null);
        $this->local = $suppliers->save(null, ['name' => 'Riyadh Office Supplies', 'country' => 'SA', 'vat_number' => '310122393500003', 'currency' => 'SAR', 'payment_terms_days' => 30, 'category' => 'office'], null);
    }

    public function test_foreign_bill_takes_the_table_rate(): void
    {
        app(ExchangeRates::class)->record('USD', CarbonImmutable::parse('2026-10-08'), '3.75', null);

        $expense = $this->record($this->hosting, '100', currency: 'USD', treatment: VatTreatment::NoVat);

        $this->assertSame('375.00', (string) $expense->net_tax);
        $this->assertSame('3.750000', (string) $expense->exchange_rate);
        $this->assertSame('table', $expense->rate_source);
        $this->assertSame('2026-10-08', $expense->rate_date->toDateString());
        $this->assertSame('2026-10-24', $expense->due_date->toDateString());
        $this->assertSame(ExpenseStatus::Unpaid, $expense->status);
    }

    public function test_no_rate_is_refused_until_entered(): void
    {
        try {
            $this->record($this->hosting, '100', currency: 'USD', treatment: VatTreatment::NoVat);
            $this->fail('An expense in dollars with no rate was taken.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('exchange_rate', $e->errors());
        }

        $expense = $this->record($this->hosting, '100', currency: 'USD', treatment: VatTreatment::NoVat, rate: '3.7512');
        $this->assertSame('375.12', (string) $expense->gross_tax);
        $this->assertSame('manual', $expense->rate_source);
    }

    public function test_recoverable_vat_is_kept_in_riyals(): void
    {
        $recoverable = $this->record($this->local, '1000', vat: '150');
        $cost = $this->record($this->local, '200', vat: '30', treatment: VatTreatment::NotRecoverable, number: 'B-2');

        $this->assertSame(['1150.00', '150.00'], [(string) $recoverable->gross_tax, (string) $recoverable->recoverable_vat_tax]);
        $this->assertSame('0.00', (string) $cost->recoverable_vat_tax);
        $this->assertTrue(AuditLog::query()->where('action', 'finance.expense.recorded')->where('entity_id', $recoverable->id)->exists());

        $this->expectException(ValidationException::class);
        $this->record($this->local, '100', vat: '15', treatment: VatTreatment::NoVat, number: 'B-3');
    }

    public function test_the_same_invoice_is_refused_twice(): void
    {
        $first = $this->record($this->local, '1000', vat: '150', number: 'INV-7');

        $this->actingAs($this->operations)->post(route('admin.finance.expenses.store'), $this->form(['supplier_invoice_number' => 'INV-7']))
            ->assertSessionHasErrors('supplier_invoice_number');

        app(Expenses::class)->void($first, 'Entered twice', null);
        $this->actingAs($this->operations)->post(route('admin.finance.expenses.store'), $this->form(['supplier_invoice_number' => 'INV-7']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, Expense::query()->where('supplier_invoice_number', 'INV-7')->count());
        $this->assertSame('Entered twice', $first->fresh()->void_reason);
    }

    public function test_a_receipt_is_private_and_previewed(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.expenses.store'), $this->form(['receipt' => UploadedFile::fake()->image('receipt.png')]))
            ->assertSessionHasNoErrors();
        $expense = Expense::query()->sole();

        $this->assertSame('image/png', $expense->receipt_mime);
        Storage::disk(ExpenseReceipts::DISK)->assertExists((string) $expense->receipt_path);

        $this->actingAs($this->operations)->get(route('admin.finance.expenses.show', $expense->id))
            ->assertOk()->assertSee(route('admin.finance.expenses.receipt.preview', $expense->id));
        $preview = $this->actingAs($this->operations)->get(route('admin.finance.expenses.receipt.preview', $expense->id))->assertOk();
        $this->assertStringStartsWith('inline', (string) $preview->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $preview->headers->get('X-Content-Type-Options'));
        $download = $this->actingAs($this->operations)->get(route('admin.finance.expenses.receipt', $expense->id))->assertOk();
        $this->assertStringStartsWith('attachment', (string) $download->headers->get('Content-Disposition'));

        auth()->logout();
        $this->get(route('admin.finance.expenses.receipt', $expense->id))->assertRedirect();
    }

    public function test_a_script_is_not_a_receipt(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.expenses.store'), $this->form([
            'receipt' => UploadedFile::fake()->createWithContent('receipt.png', '<html><script>alert(1)</script></html>'),
        ]))->assertSessionHas('error');

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_named_supplier_is_only_archived(): void
    {
        $this->record($this->local, '1000', vat: '150');

        $this->expectException(DomainException::class);
        app(Suppliers::class)->remove($this->local, null);
    }

    public function test_a_draft_is_recorded_when_billed(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.expenses.store'), $this->form(['draft' => '1']))->assertRedirect();
        $draft = Expense::query()->sole();
        $this->assertSame(ExpenseStatus::Draft, $draft->status);

        $this->actingAs($this->operations)->post(route('admin.finance.expenses.confirm', $draft->id))->assertRedirect();
        $this->assertSame(ExpenseStatus::Unpaid, $draft->fresh()->status);

        $this->actingAs($this->operations)->post(route('admin.finance.expenses.update', $draft->id), $this->form(['net' => '2000', 'vat' => '300']))->assertRedirect();
        $this->assertSame('2300.00', (string) $draft->fresh()->gross);
        $this->assertTrue(AuditLog::query()->where('action', 'finance.expense.updated')->exists());
    }

    public function test_the_list_filters_and_exports(): void
    {
        $this->record($this->local, '1000', vat: '150', number: 'OFF-1');
        $this->record($this->hosting, '100', currency: 'USD', treatment: VatTreatment::NoVat, rate: '3.75', number: 'HST-1');

        $this->actingAs($this->operations)->get(route('admin.finance.expenses', ['supplier' => $this->hosting->id]))
            ->assertOk()->assertSee('HST-1')->assertDontSee('OFF-1');

        $csv = $this->actingAs($this->operations)->get(route('admin.finance.expenses.export', ['category' => 'office']))->assertOk()->streamedContent();
        $this->assertStringContainsString('OFF-1', $csv);
        $this->assertStringNotContainsString('HST-1', $csv);
    }

    private function record(Supplier $supplier, string $net, string $vat = '0', string $currency = 'SAR', VatTreatment $treatment = VatTreatment::Recoverable, ?string $rate = null, ?string $number = 'B-1'): Expense
    {
        return app(Expenses::class)->record(new ExpenseEntry(
            supplierId: (string) $supplier->id,
            expenseDate: CarbonImmutable::parse('2026-10-10'),
            description: 'A bill',
            category: (string) $supplier->category,
            currency: $currency,
            net: $net,
            vat: $vat,
            vatTreatment: $treatment,
            supplierInvoiceNumber: $number,
            costCentre: 'platform',
            exchangeRate: $rate,
        ), null, false, null);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return [
            'supplier_id' => $this->local->id, 'expense_date' => '2026-10-10', 'supplier_invoice_number' => 'INV-1',
            'description' => 'Paper and toner', 'category' => 'office', 'cost_centre' => 'administration', 'currency' => 'SAR',
            'net' => '1000', 'vat' => '150', 'vat_treatment' => 'recoverable', ...$overrides,
        ];
    }
}
