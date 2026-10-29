<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Contract;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\DeliveryNote;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * N-5: summary invoices and contracts are business-to-business billing. A
 * business whose type sells over the counter (its invoices start
 * simplified: a shop, a pharmacy, a school) is not shown them in the menu -
 * unless it has delivery notes or contracts, which are never hidden. A type
 * that bills businesses, a mixed one, or none at all keeps both.
 */
class BillingNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003']);
    }

    public function test_a_shop_is_not_shown_them(): void
    {
        $this->acme->forceFill(['business_type' => 'retail'])->save();

        $this->dashboard()
            ->assertDontSee(route('portal.summaries.index'), false)
            ->assertDontSee(route('portal.contracts.index'), false);
    }

    public function test_a_wholesaler_is_shown_them(): void
    {
        $this->acme->forceFill(['business_type' => 'wholesale'])->save();

        $this->dashboard()
            ->assertSee(route('portal.summaries.index'), false)
            ->assertSee(route('portal.contracts.index'), false);
    }

    public function test_no_type_shows_them(): void
    {
        $this->dashboard()
            ->assertSee(route('portal.summaries.index'), false)
            ->assertSee(route('portal.contracts.index'), false);
    }

    public function test_a_shop_with_data_keeps_them(): void
    {
        $this->acme->forceFill(['business_type' => 'retail'])->save();
        $customer = Customer::acrossTenants()->create(['org_id' => $this->acme->id, 'name' => 'Beta']);
        DeliveryNote::acrossTenants()->create([
            'org_id' => $this->acme->id, 'customer_id' => $customer->id, 'number' => 'DN-1', 'delivered_on' => '2026-10-03',
            'lines' => [], 'net_amount' => '0.00',
        ]);
        Contract::acrossTenants()->create([
            'org_id' => $this->acme->id, 'customer_id' => $customer->id, 'reference' => 'C-1', 'title' => 'Fit-out',
            'value' => '1000.00', 'tax_rate' => '15.00',
        ]);

        $this->dashboard()
            ->assertSee(route('portal.summaries.index'), false)
            ->assertSee(route('portal.contracts.index'), false);
    }

    private function dashboard(): TestResponse
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.dashboard'))->assertOk();
    }
}
