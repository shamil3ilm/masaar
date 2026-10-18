<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saved customers and products, and the invoice form filling itself from them.
 */
class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const VAT = '310122393500003';

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_a_member_adds_a_customer(): void
    {
        $this->portal()->post('/portal/customers', $this->customerFields())
            ->assertRedirect(route('portal.customers.index'));

        $customer = $this->customers()->firstOrFail();
        $this->assertSame('Gulf Supplies', $customer->name);
        $this->assertSame($this->acme->id, $customer->org_id);
    }

    public function test_a_bad_vat_number_is_refused(): void
    {
        $this->portal()->post('/portal/customers', $this->customerFields(['vat_number' => '123']))
            ->assertSessionHasErrors('vat_number');
    }

    public function test_a_vat_number_is_kept_once(): void
    {
        $this->portal()->post('/portal/customers', $this->customerFields());
        $this->portal()->post('/portal/customers', $this->customerFields(['name' => 'Again']))
            ->assertSessionHasErrors('vat_number');

        $this->assertSame(1, $this->customers()->count());
    }

    public function test_another_company_cannot_open_it(): void
    {
        $this->portal()->post('/portal/customers', $this->customerFields());
        $customer = $this->customers()->firstOrFail();

        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($rival->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($outsider)->withSession(['portal_org_id' => $rival->id])
            ->get(route('portal.customers.edit', $customer->id))
            ->assertNotFound();
    }

    public function test_a_viewer_cannot_add_one(): void
    {
        $this->acme->users()->updateExistingPivot($this->member->id, ['role' => 'viewer']);

        $this->portal()->post('/portal/customers', $this->customerFields())->assertForbidden();
    }

    public function test_a_product_needs_a_known_unit(): void
    {
        $this->portal()->post('/portal/products', $this->productFields(['unit_code' => 'NOPE']))
            ->assertSessionHasErrors('unit_code');

        $this->portal()->post('/portal/products', $this->productFields())
            ->assertRedirect(route('portal.products.index'));

        $this->assertSame('Consulting hour', Product::withoutTenantScope(fn () => Product::query()->value('name')));
    }

    /**
     * The form carries what each choice fills in; the pickers submit nothing.
     */
    public function test_the_invoice_form_offers_them(): void
    {
        $this->portal()->post('/portal/customers', $this->customerFields());
        $this->portal()->post('/portal/products', $this->productFields());

        $this->portal()->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('data-customer-picker', false)
            ->assertSee('data-product-picker', false)
            ->assertSee('Gulf Supplies')
            ->assertSee('Consulting hour');
    }

    public function test_an_archived_one_is_not_offered(): void
    {
        $this->portal()->post('/portal/customers', $this->customerFields());
        $customer = $this->customers()->firstOrFail();

        $this->portal()->post(route('portal.customers.archive', $customer->id));

        // The only customer is archived, so there is nothing to pick from.
        $this->portal()->get('/portal/invoices/create')->assertOk()->assertDontSee('data-customer-picker', false);
        $this->assertNotNull($customer->fresh()->archived_at);
    }

    /**
     * Ticking the box keeps the buyer; a second invoice to them updates the
     * same record.
     */
    public function test_the_buyer_is_saved_once(): void
    {
        $this->portal()->post('/portal/invoices', $this->invoiceFields())->assertRedirect();
        $this->portal()->post('/portal/invoices', $this->invoiceFields(['buyer_name' => 'Gulf Supplies Co', 'buyer_name_ar' => 'شركة الخليج للتوريدات']))->assertRedirect();

        $this->assertSame(1, $this->customers()->count());
        $customer = $this->customers()->firstOrFail();
        $this->assertSame('Gulf Supplies Co', $customer->name);
        $this->assertSame('Riyadh', $customer->city);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customerFields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Gulf Supplies',
            'name_ar' => 'الخليج للتوريدات',
            'vat_number' => self::VAT,
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'country_code' => 'SA',
            'email' => 'ap@gulf.test',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productFields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Consulting hour',
            'name_ar' => 'ساعة استشارية',
            'unit_code' => 'HUR',
            'unit_price' => '250',
            'tax_rate' => '15',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoiceFields(array $overrides = []): array
    {
        return array_merge([
            'type' => 'standard',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Gulf Supplies',
            'buyer_name_ar' => 'الخليج للتوريدات',
            'buyer_vat_number' => self::VAT,
            'buyer_address' => [
                'street' => 'King Fahd Road',
                'building_number' => '1234',
                'district' => 'Olaya',
                'city' => 'Riyadh',
                'postal_code' => '12345',
                'country_code' => 'SA',
            ],
            'lines' => [['description' => 'Consulting hour', 'description_ar' => 'ساعة استشارية', 'quantity' => '2', 'unit_price' => '250', 'tax_rate' => '15', 'unit_code' => 'HUR']],
            'save_customer' => '1',
        ], $overrides);
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }

    /**
     * @return Builder<Customer>
     */
    private function customers(): Builder
    {
        return Customer::withoutTenantScope(fn () => Customer::query());
    }
}
