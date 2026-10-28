<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An insurer kept as a customer: the buyer of the insurer's share of a split
 * sale, invoiced on a tax invoice to a registered buyer, so it keeps the VAT
 * number and the address that invoice needs.
 */
class InsurerCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $clinic;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinic = Organization::create(['name' => 'Al Shifa Clinic', 'country' => 'SA']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->clinic->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_an_insurer_is_kept_as_one(): void
    {
        $this->portal()->post('/portal/customers', $this->fields())->assertSessionHasNoErrors();

        $insurer = $this->customer();
        $this->assertTrue($insurer->is_insurer);
        $this->assertSame(['311111111100003', 'Riyadh'], [$insurer->vat_number, $insurer->city]);
    }

    public function test_an_insurer_needs_vat_and_address(): void
    {
        $this->portal()->post('/portal/customers', $this->fields(['vat_number' => null, 'street' => null, 'city' => null]))
            ->assertSessionHasErrors(['vat_number', 'street', 'city']);
    }

    public function test_unticked_it_is_no_insurer(): void
    {
        $this->portal()->post('/portal/customers', $this->fields())->assertSessionHasNoErrors();
        $this->portal()->post('/portal/customers/'.$this->customer()->id, $this->fields(['is_insurer' => null]))->assertSessionHasNoErrors();

        $this->assertFalse($this->customer()->is_insurer);
    }

    public function test_the_form_offers_the_insurer_type(): void
    {
        $this->asRequest(fn () => $this->portal()->get('/portal/customers/new')
            ->assertOk()
            ->assertSee('name="is_insurer"', false));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return array_filter([
            'name' => 'Gulf Health Insurance',
            'name_ar' => 'الخليج للتأمين الصحي',
            'vat_number' => '311111111100003',
            'is_insurer' => '1',
            'street' => 'King Abdullah Road',
            'building_number' => '7788',
            'district' => 'Al Malqa',
            'city' => 'Riyadh',
            'postal_code' => '13521',
            'country_code' => 'SA',
            ...$overrides,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function customer(): Customer
    {
        return Customer::acrossTenants()->sole();
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->clinic->id]);
    }
}
