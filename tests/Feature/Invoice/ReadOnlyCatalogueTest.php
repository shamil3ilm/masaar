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
 * A role that reads only is not handed a form it cannot save: no New
 * button, no form to type into, and a saved record shown without Save.
 */
class ReadOnlyCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);
        $this->accountant = User::factory()->create();
        $this->accountant->organizations()->attach($this->acme->id, ['role' => 'accountant', 'status' => 'active']);
    }

    public function test_lists_offer_no_new_button(): void
    {
        $this->page('/portal/customers')->assertOk()->assertDontSee(route('portal.customers.create'));
        $this->page('/portal/products')->assertOk()->assertDontSee(route('portal.products.create'));
    }

    public function test_new_forms_are_not_opened(): void
    {
        $this->page('/portal/customers/new')->assertForbidden();
        $this->page('/portal/products/new')->assertForbidden();
    }

    public function test_a_record_is_shown_read_only(): void
    {
        $customer = new Customer(['org_id' => $this->acme->id, 'name' => 'Gulf Supplies', 'name_ar' => 'إمدادات الخليج', 'country_code' => 'SA']);
        Customer::withoutTenantScope(fn () => $customer->save());
        $product = new Product(['org_id' => $this->acme->id, 'name' => 'Chair', 'name_ar' => 'كرسي', 'unit_code' => 'C62', 'unit_price' => '100', 'tax_rate' => 15]);
        Product::withoutTenantScope(fn () => $product->save());

        $this->page('/portal/customers/'.$customer->id)->assertOk()
            ->assertSee('<fieldset disabled', false)
            ->assertDontSee('Save customer')
            ->assertDontSee(route('portal.customers.archive', $customer->id));

        $this->page('/portal/products/'.$product->id)->assertOk()
            ->assertSee('<fieldset disabled', false)
            ->assertDontSee('Save product');
    }

    private function page(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->accountant)->withSession(['portal_org_id' => $this->acme->id])->get($uri));
    }
}
