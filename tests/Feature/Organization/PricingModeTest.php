<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether the company's prices include VAT, set from the company page.
 *
 * Every invoice that does not say otherwise is priced by this, and only the
 * API could set it: a retailer pricing shelf prices with VAT in them had no
 * way, in the portal, to stop every invoice adding 15% on top.
 */
class PricingModeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_the_page_offers_the_setting(): void
    {
        $this->asRequest(fn () => $this->portal($this->admin)->get('/portal/company')
            ->assertOk()
            ->assertSee(route('portal.company.pricing'), false)
            ->assertSee('<option value="0" selected', false));
    }

    public function test_an_admin_turns_it_on(): void
    {
        $this->portal($this->admin)->post('/portal/company/pricing', ['prices_include_vat' => '1'])
            ->assertRedirect(route('portal.company.edit'))
            ->assertSessionHas('success');

        $this->assertTrue($this->acme->fresh()->prices_include_vat);

        $this->portal($this->admin)->post('/portal/company/pricing', ['prices_include_vat' => '0']);
        $this->assertFalse($this->acme->fresh()->prices_include_vat);
    }

    public function test_it_must_be_a_choice(): void
    {
        $this->portal($this->admin)->post('/portal/company/pricing', ['prices_include_vat' => 'maybe'])
            ->assertSessionHasErrors('prices_include_vat');

        $this->assertFalse($this->acme->fresh()->prices_include_vat);
    }

    public function test_a_member_cannot_change_it(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->portal($member)->post('/portal/company/pricing', ['prices_include_vat' => '1'])->assertForbidden();

        $this->assertFalse($this->acme->fresh()->prices_include_vat);
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
