<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Archive if used, remove if not (owner decision): a customer or product an
 * invoice used stays for the record and can only be archived; one never
 * used can be removed, and is brought back from the list's Removed view. The
 * API keeps the same rule, answering 409 with the archive route.
 */
class CatalogueRemovalTest extends TestCase
{
    use RefreshDatabase;

    private const VAT = '310122393500003';

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_an_unused_customer_is_removed(): void
    {
        $customer = $this->customer();

        $this->portal()->get(route('portal.customers.edit', $customer->id))->assertSee(route('portal.customers.remove', $customer->id), false);
        $this->portal()->post(route('portal.customers.remove', $customer->id))->assertRedirect(route('portal.customers.index'));

        $this->assertSoftDeleted('customers', ['id' => $customer->id, 'deleted_by' => $this->member->id]);
    }

    public function test_a_used_customer_is_only_archived(): void
    {
        $customer = $this->customer();
        $this->invoiceFor($customer);

        $this->portal()->get(route('portal.customers.edit', $customer->id))->assertDontSee(route('portal.customers.remove', $customer->id), false);
        $this->portal()->post(route('portal.customers.remove', $customer->id))->assertSessionHas('error');

        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_a_removed_customer_comes_back(): void
    {
        $customer = $this->customer();
        $this->portal()->post(route('portal.customers.remove', $customer->id));

        // Gone from the list, as the flash message says.
        $this->portal()->get(route('portal.customers.index'))->assertDontSee(route('portal.customers.edit', $customer->id), false);
        $this->portal()->get(route('portal.customers.index', ['archived' => 'removed']))
            ->assertOk()
            ->assertSee('Gulf Supplies')
            ->assertSee(route('portal.customers.restore-removed', $customer->id), false);

        $this->portal()->post(route('portal.customers.restore-removed', $customer->id))->assertSessionHas('success');

        $this->assertNotSoftDeleted('customers', ['id' => $customer->id, 'deleted_by' => null]);
    }

    public function test_a_used_product_is_only_archived(): void
    {
        $used = $this->product('Consulting hour', 'CH-1');
        $unused = $this->product('Training day', 'TD-1');
        $this->lineFrom($used);

        $this->portal()->post(route('portal.products.remove', $used->id))->assertSessionHas('error');
        $this->portal()->post(route('portal.products.remove', $unused->id))->assertRedirect(route('portal.products.index'));

        $this->assertNotSoftDeleted('products', ['id' => $used->id]);
        $this->assertSoftDeleted('products', ['id' => $unused->id, 'deleted_by' => $this->member->id]);
    }

    public function test_a_removed_product_comes_back(): void
    {
        $product = $this->product('Training day', 'TD-1');
        $this->portal()->post(route('portal.products.remove', $product->id));

        $this->portal()->get(route('portal.products.index', ['archived' => 'removed']))->assertOk()->assertSee('Training day');
        $this->portal()->post(route('portal.products.restore-removed', $product->id))->assertSessionHas('success');

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    /** Picked by id or named by its code, the line keeps which product it was. */
    public function test_a_line_records_its_product(): void
    {
        $byId = $this->product('Consulting hour', 'CH-1');
        $byCode = $this->product('Training day', 'TD-1');

        $this->api()->postJson('/api/invoices', [
            'invoice_number' => 'INV-1', 'type' => 'simplified', 'issue_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Consulting hour', 'description_ar' => 'ساعة استشارية', 'quantity' => 1, 'unit_price' => 250, 'product_id' => $byId->id],
                ['description' => 'Training day', 'description_ar' => 'يوم تدريبي', 'quantity' => 1, 'unit_price' => 900, 'product_code' => 'TD-1'],
                ['description' => 'Something else', 'description_ar' => 'شيء آخر', 'quantity' => 1, 'unit_price' => 10],
            ],
        ])->assertCreated();

        $this->assertSame(
            ['Consulting hour' => $byId->id, 'Training day' => $byCode->id, 'Something else' => null],
            InvoiceLine::query()->pluck('product_id', 'description')->all(),
        );
    }

    public function test_the_api_refuses_a_used_one(): void
    {
        $customer = $this->customer();
        $this->invoiceFor($customer);

        $this->api()->deleteJson('/api/customers/'.$customer->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RECORD_IN_USE')
            ->assertJsonPath('error.details.archive', url('/api/customers/'.$customer->id.'/archive'));

        $this->api()->postJson('/api/customers/'.$customer->id.'/archive')->assertOk();
        $this->assertNotNull($customer->fresh()->archived_at);
        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_the_api_removes_an_unused_one(): void
    {
        $product = $this->product('Training day', 'TD-1');

        $this->api()->deleteJson('/api/products/'.$product->id)->assertOk();
        $this->api()->deleteJson('/api/products/'.$product->id)->assertNotFound();

        $this->assertSoftDeleted('products', ['id' => $product->id, 'deleted_by' => $this->member->id]);
    }

    private function customer(): Customer
    {
        return Customer::acrossTenants()->create([
            'org_id' => $this->acme->id, 'name' => 'Gulf Supplies', 'name_ar' => 'الخليج للتوريدات', 'vat_number' => self::VAT, 'country_code' => 'SA',
        ]);
    }

    private function product(string $name, string $sku): Product
    {
        return Product::acrossTenants()->create([
            'org_id' => $this->acme->id, 'name' => $name, 'name_ar' => 'منتج', 'sku' => $sku, 'unit_code' => 'C62', 'unit_price' => 100, 'tax_rate' => 15,
        ]);
    }

    private function invoiceFor(Customer $customer): Invoice
    {
        return Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id, 'type' => 'standard', 'status' => 'draft', 'issue_date' => now()->toDateString(), 'currency' => 'SAR',
            'buyer_name' => $customer->name, 'buyer_vat_number' => $customer->vat_number, 'subtotal' => '100', 'tax_amount' => '15', 'total' => '115',
        ]);
    }

    private function lineFrom(Product $product): void
    {
        $invoice = $this->invoiceFor($this->customer());
        $invoice->lines()->create([
            'description' => $product->name, 'quantity' => '1', 'unit_code' => 'C62', 'unit_price' => '100', 'tax_rate' => '15',
            'tax_amount' => '15', 'line_total' => '115', 'product_id' => $product->id,
        ]);
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }

    private function api(): static
    {
        return $this->withToken(JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'member'])->fromUser($this->member));
    }
}
