<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice form's customer and product pickers search as you type
 * (resources/js/combobox.js), for a catalogue too long to send whole. Only
 * the active organization's records answer, and only active ones.
 */
class CatalogueLookupTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $other;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003']);
        $this->other = Organization::create(['name' => 'Other Co', 'country' => 'SA', 'vat_number' => '300000000000004']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_customers_are_found_by_either_name(): void
    {
        $gulf = $this->customer($this->acme, 'Gulf Supplies', 'الخليج للتوريدات');
        $this->customer($this->acme, 'Northwind', 'نورثويند');

        $this->portal()->getJson(route('portal.customers.lookup', ['q' => 'gulf']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', $gulf->id)
            ->assertJsonPath('data.0.fields.buyer_name', 'Gulf Supplies');

        $this->portal()->getJson(route('portal.customers.lookup', ['q' => 'الخليج']))
            ->assertJsonPath('data.0.value', $gulf->id);
    }

    public function test_another_organizations_customers_stay_hidden(): void
    {
        $this->customer($this->other, 'Gulf Elsewhere', null);

        $this->portal()->getJson(route('portal.customers.lookup', ['q' => 'gulf']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_archived_customers_are_not_offered(): void
    {
        $customer = $this->customer($this->acme, 'Gulf Supplies', null);
        $customer->forceFill(['archived_at' => now()])->save();

        $this->portal()->getJson(route('portal.customers.lookup', ['q' => 'gulf']))->assertJsonCount(0, 'data');
    }

    public function test_products_are_found_by_code(): void
    {
        $product = Product::acrossTenants()->create([
            'org_id' => $this->acme->id, 'name' => 'Paper', 'name_ar' => 'ورق', 'sku' => 'A4-500', 'unit_code' => 'C62', 'unit_price' => 20, 'tax_rate' => 15,
        ]);

        $this->portal()->getJson(route('portal.products.lookup', ['q' => 'a4']))
            ->assertOk()
            ->assertJsonPath('data.0.value', $product->id)
            ->assertJsonPath('data.0.fields.unit_code', 'C62');
    }

    public function test_an_empty_query_finds_nothing(): void
    {
        $this->customer($this->acme, 'Gulf Supplies', null);

        $this->portal()->getJson(route('portal.customers.lookup'))->assertOk()->assertJsonCount(0, 'data');
    }

    private function customer(Organization $organization, string $name, ?string $arabic): Customer
    {
        return Customer::acrossTenants()->create([
            'org_id' => $organization->id, 'name' => $name, 'name_ar' => $arabic, 'country_code' => 'SA',
        ]);
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
