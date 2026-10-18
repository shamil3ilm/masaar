<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer known by something other than a VAT number, and the terms it
 * is paid on, kept for its next invoice.
 *
 * A business below the VAT threshold is invoiced by its commercial
 * registration (BR-KSA-81), a citizen buying private healthcare by national
 * ID (BR-KSA-49) - and a customer could keep neither, nor its usual payment
 * terms, so each was retyped on every invoice.
 */
class CustomerTermsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_the_customer_keeps_them(): void
    {
        $this->portal()->post('/portal/customers', $this->fields())->assertSessionHasNoErrors();

        $customer = $this->customer();
        $this->assertSame(['1010202020', 'CRN', '30 days net'], [$customer->buyer_id, $customer->buyer_id_scheme, $customer->payment_terms]);
    }

    public function test_the_form_asks_for_them(): void
    {
        $this->asRequest(fn () => $this->portal()->get('/portal/customers/new')
            ->assertOk()
            ->assertSee('name="buyer_id_scheme"', false)
            ->assertSee('name="buyer_id"', false)
            ->assertSee('name="payment_terms"', false));
    }

    /** An identifier says nothing without the register it is from. */
    public function test_an_identifier_needs_its_scheme(): void
    {
        $this->portal()->post('/portal/customers', $this->fields(['buyer_id_scheme' => null]))
            ->assertSessionHasErrors('buyer_id_scheme');
        $this->portal()->post('/portal/customers', $this->fields(['buyer_id_scheme' => 'XYZ']))
            ->assertSessionHasErrors('buyer_id_scheme');
        $this->portal()->post('/portal/customers', $this->fields(['buyer_id' => '1010 2020']))
            ->assertSessionHasErrors('buyer_id');
    }

    /** The invoice form's customer picker fills them in. */
    public function test_a_new_invoice_starts_with_them(): void
    {
        $this->portal()->post('/portal/customers', $this->fields());

        $this->assertSame(
            ['buyer_id_scheme' => 'CRN', 'buyer_id' => '1010202020', 'payment_terms' => '30 days net'],
            array_intersect_key($this->customer()->buyerFields(), array_flip(['buyer_id_scheme', 'buyer_id', 'payment_terms'])),
        );

        $this->asRequest(fn () => $this->portal()->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('&quot;payment_terms&quot;:&quot;30 days net&quot;', false)
            ->assertSee('&quot;buyer_id&quot;:&quot;1010202020&quot;', false));
    }

    /**
     * Saved from an invoice, a buyer with no VAT number is kept by its
     * identifier, and saved again it is the same customer.
     */
    public function test_an_invoice_remembers_them(): void
    {
        foreach (['30 days net', '45 days net'] as $terms) {
            $this->portal()->post('/portal/invoices', [
                'type' => 'standard',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'buyer_name' => 'Small Supplies',
                'buyer_name_ar' => 'التوريدات الصغيرة',
                'buyer_id' => '1010202020',
                'buyer_id_scheme' => 'CRN',
                'payment_terms' => $terms,
                'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
                'save_customer' => '1',
                'lines' => [['description' => 'Tea', 'description_ar' => 'شاي', 'quantity' => '1', 'unit_price' => '10']],
            ])->assertSessionHasNoErrors();
        }

        $customer = $this->customer();
        $this->assertSame(['1010202020', 'CRN', '45 days net'], [$customer->buyer_id, $customer->buyer_id_scheme, $customer->payment_terms]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return array_filter([
            'name' => 'Small Supplies',
            'name_ar' => 'التوريدات الصغيرة',
            'buyer_id' => '1010202020',
            'buyer_id_scheme' => 'CRN',
            'payment_terms' => '30 days net',
            'street' => 'Olaya Street',
            'building_number' => '4321',
            'district' => 'Al Murooj',
            'city' => 'Riyadh',
            'postal_code' => '11564',
            'country_code' => 'SA',
            ...$overrides,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function customer(): Customer
    {
        return Customer::withoutTenantScope(fn () => Customer::query()->sole());
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
