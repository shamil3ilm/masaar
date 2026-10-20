<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Billing\Settings\FinanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin › Finance › Suppliers: added with a checked IBAN, archived out of
 * the pickers, removed and restored while unused; and the expense
 * categories and cost centres an admin keeps.
 */
class SuppliersTest extends TestCase
{
    use RefreshDatabase;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operations = $this->admin('operations');
    }

    public function test_a_supplier_keeps_a_checked_iban(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.store'), $this->form(['iban' => 'gb82 west 1234 5698 7654 32']))
            ->assertRedirect();

        $supplier = Supplier::query()->sole();
        $this->assertSame('GB82WEST12345698765432', $supplier->iban);
        $this->assertSame('IE', $supplier->country);
        $this->assertSame((string) $this->operations->id, (string) $supplier->created_by);
        $this->assertTrue(AuditLog::query()->where('action', 'finance.supplier.created')->where('entity_id', $supplier->id)->exists());

        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.update', $supplier->id), $this->form(['iban' => 'GB82WEST12345698765431']))
            ->assertSessionHasErrors('iban');
        $this->assertSame('GB82WEST12345698765432', $supplier->fresh()->iban);
    }

    public function test_country_currency_and_category_are_checked(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.store'), $this->form(['country' => 'XX']))->assertSessionHasErrors('country');
        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.store'), $this->form(['currency' => 'JPY']))->assertSessionHasErrors('currency');
        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.store'), $this->form(['category' => 'nothing']))->assertSessionHasErrors('category');

        $this->assertSame(0, Supplier::query()->count());
    }

    public function test_unused_supplier_is_removed_then_restored(): void
    {
        $supplier = app(Suppliers::class)->save(null, $this->form(), null);

        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.remove', $supplier->id))->assertRedirect(route('admin.finance.suppliers'));
        $this->assertSoftDeleted($supplier);
        $this->assertSame((string) $this->operations->id, (string) Supplier::withTrashed()->find($supplier->id)->deleted_by);

        $this->actingAs($this->operations)->get(route('admin.finance.suppliers', ['showing' => 'removed']))->assertOk()->assertSee('Cloud Hosting Ltd');

        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.restore', $supplier->id))->assertRedirect();
        $this->assertNotSoftDeleted($supplier);
    }

    public function test_archived_supplier_leaves_the_pickers(): void
    {
        $suppliers = app(Suppliers::class);
        $supplier = $suppliers->save(null, $this->form(), null);

        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.archive', $supplier->id))->assertRedirect();

        $this->assertNotNull($supplier->fresh()->archived_at);
        $this->assertSame((string) $this->operations->id, (string) $supplier->fresh()->archived_by);
        $this->assertCount(0, $suppliers->choosable());
        $this->assertCount(1, $suppliers->choosable((string) $supplier->id));
        $this->actingAs($this->operations)->get(route('admin.finance.suppliers'))->assertOk()->assertDontSee(route('admin.finance.suppliers.show', $supplier->id));

        $this->actingAs($this->operations)->post(route('admin.finance.suppliers.unarchive', $supplier->id));
        $this->assertCount(1, $suppliers->choosable());
    }

    public function test_support_reads_but_cannot_change(): void
    {
        $support = $this->admin('support');
        $supplier = app(Suppliers::class)->save(null, $this->form(), null);

        $this->actingAs($support)->get(route('admin.finance.suppliers.show', $supplier->id))->assertOk()->assertSee('Cloud Hosting Ltd');
        $this->actingAs($support)->post(route('admin.finance.suppliers.store'), $this->form())->assertForbidden();
        $this->actingAs($support)->post(route('admin.finance.suppliers.remove', $supplier->id))->assertForbidden();
    }

    public function test_the_list_filters_and_exports(): void
    {
        $suppliers = app(Suppliers::class);
        $suppliers->save(null, $this->form(), null);
        $suppliers->save(null, $this->form(['name' => 'Gateway Co', 'country' => 'SA', 'currency' => 'SAR', 'category' => 'payment_provider', 'iban' => 'SA0380000000608010167519']), null);

        $this->actingAs($this->operations)->get(route('admin.finance.suppliers', ['category' => 'payment_provider']))
            ->assertOk()->assertSee('Gateway Co')->assertDontSee('Cloud Hosting Ltd');

        $csv = $this->actingAs($this->operations)->get(route('admin.finance.suppliers.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('SA0380000000608010167519', $csv);
        $this->assertStringContainsString('Hosting and infrastructure', $csv);
    }

    public function test_categories_and_cost_centres_are_editable(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.settings.lists.store', 'categories'), ['name' => 'Domain names'])->assertRedirect();
        $this->actingAs($this->operations)->post(route('admin.finance.settings.lists.remove', ['cost-centres', 'sales']))->assertRedirect();

        $settings = app(FinanceSettings::class);
        $this->assertSame('Domain names', $settings->expenseCategories()['domain_names']);
        $this->assertArrayNotHasKey('sales', $settings->costCentres());

        $this->actingAs($this->operations)->post(route('admin.finance.settings.lists.store', 'categories'), ['name' => 'domain names'])->assertSessionHasErrors('name');
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Cloud Hosting Ltd', 'country' => 'IE', 'vat_number' => 'IE6388047V', 'address' => 'Dublin',
            'currency' => 'USD', 'payment_terms_days' => 14, 'bank_name' => 'West Bank', 'iban' => 'GB82WEST12345698765432',
            'swift' => 'WESTGB2L', 'category' => 'hosting', 'notes' => null, ...$overrides,
        ];
    }

    private function admin(string $role): User
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
        $admin->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        return $admin;
    }
}
