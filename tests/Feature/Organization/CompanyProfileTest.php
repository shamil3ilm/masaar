<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The seller details ZATCA requires, and the only ways in for them.
 *
 * The address and CR number had no way in except a sandbox command, so no
 * organization could complete the profile onboarding requires. Now the
 * portal's company page and the API take them, in ZATCA's shapes.
 */
class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_the_company_page_completes_the_profile(): void
    {
        $this->assertFalse($this->acme->hasCompleteZatcaProfile());

        $this->asMember('admin')->post('/portal/company', $this->details())
            ->assertRedirect(route('portal.company.edit'))
            ->assertSessionHas('success');

        $acme = $this->acme->fresh();

        $this->assertTrue($acme->hasCompleteZatcaProfile());
        $this->assertSame('1010010000', $acme->cr_number);
        $this->assertSame('شركة أكمي', $acme->name_ar);
    }

    public function test_only_an_org_admin_changes_it(): void
    {
        $this->asMember('member')->get('/portal/company')->assertForbidden();
        $this->asMember('member')->post('/portal/company', $this->details())->assertForbidden();

        $this->asMember('admin')->get('/portal/company')->assertOk()->assertSee('National address');
    }

    /**
     * Each in the shape ZATCA checks, refused here rather than on an
     * invoice that has already been signed.
     */
    public function test_zatca_shapes_are_enforced(): void
    {
        $this->asMember('admin')->post('/portal/company', $this->details([
            'vat_number' => '123456789012345',
            'building_number' => '12',
            'postal_code' => '1234',
            'cr_number' => '12345',
        ]))->assertSessionHasErrors(['vat_number', 'building_number', 'postal_code', 'cr_number']);

        $this->assertNull($this->acme->fresh()->street);
    }

    public function test_the_page_needs_every_required_field(): void
    {
        $this->asMember('admin')->post('/portal/company', $this->details(['district' => '']))
            ->assertSessionHasErrors('district');
    }

    /**
     * The certificate names the VAT number, so it stays once onboarded.
     */
    public function test_vat_is_fixed_once_onboarded(): void
    {
        $this->acme->forceFill([
            'compliance_profile' => ['vat_number' => '300000000000003', 'zatca_onboarded' => true],
        ])->save();

        $this->asMember('admin')->post('/portal/company', $this->details(['vat_number' => '311111111111113']))
            ->assertSessionHasErrors('vat_number');

        $this->asMember('admin')->post('/portal/company', $this->details(['vat_number' => '300000000000003', 'city' => 'Jeddah']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Jeddah', $this->acme->fresh()->city);
    }

    public function test_the_api_takes_the_address(): void
    {
        $this->withToken($this->token('admin'))
            ->putJson("/api/organizations/{$this->acme->id}", $this->details())
            ->assertOk()
            ->assertJsonPath('data.organization.building_number', '1234');

        $this->assertTrue($this->acme->fresh()->hasCompleteZatcaProfile());
    }

    public function test_the_api_checks_what_arrives(): void
    {
        $this->withToken($this->token('admin'))
            ->putJson("/api/organizations/{$this->acme->id}", ['postal_code' => '123'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.postal_code.0', 'The postal code is 5 digits.');
    }

    /**
     * Saudi only while UAE filing is switched off.
     */
    public function test_uae_registration_follows_the_switch(): void
    {
        config(['fta.enabled' => false]);

        $this->withToken($this->token('admin'))
            ->postJson('/api/organizations', ['name' => 'Gulf', 'country' => 'AE'])
            ->assertUnprocessable();

        config(['fta.enabled' => true]);

        $this->withToken($this->token('admin'))
            ->postJson('/api/organizations', ['name' => 'Gulf', 'country' => 'AE'])
            ->assertCreated();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Trading',
            'name_ar' => 'شركة أكمي',
            'vat_number' => '300000000000003',
            'cr_number' => '1010010000',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ], $overrides);
    }

    private function asMember(string $role): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }

    private function token(string $role): string
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return JWTAuth::claims(['org_id' => $this->acme->id, 'role' => $role])->fromUser($user);
    }
}
