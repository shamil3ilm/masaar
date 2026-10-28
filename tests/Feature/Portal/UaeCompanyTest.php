<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The company page of a UAE organization: the seller details PINT AE needs,
 * saved where the API saves them. A Saudi organization's page is in
 * JurisdictionIsolationTest.
 */
class UaeCompanyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'uae-company-token';

    private Organization $gulf;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        $this->gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE']);
        $this->admin = $this->member($this->gulf, 'admin');
    }

    public function test_uae_page_asks_uae_details(): void
    {
        $this->asRequest(fn () => $this->open($this->admin, $this->gulf)
            ->assertOk()
            ->assertSee('name="emirate"', false)
            ->assertSee('name="trn"', false)
            ->assertSee('name="tin"', false)
            ->assertSee('name="endpoint_id"', false)
            ->assertSee('name="legal_registration_type"', false)
            ->assertDontSee('name="vat_number"', false)
            ->assertDontSee('National address'));
    }

    public function test_uae_details_saved_to_profile(): void
    {
        $this->save($this->details())->assertRedirect(route('portal.company.edit'))->assertSessionHas('success');

        $organization = $this->gulf->fresh();
        $settings = ComplianceProfile::acrossTenants()
            ->where('org_id', $this->gulf->id)->where('jurisdiction', 'AE')->firstOrFail()->settings;

        $this->assertSame(['Al Wasl Road', 'Dubai'], [$organization->street, $organization->city]);
        $this->assertSame('100123456700003', $organization->vat_number);
        $this->assertSame('1001234567', $settings['tin']);
        $this->assertSame(['0235:1001234567', 'DXB', 'TL'], [$settings['endpoint_id'], $settings['emirate'], $settings['legal_registration_type']]);

        // Shown back as saved.
        $this->asRequest(fn () => $this->open($this->admin, $this->gulf)
            ->assertSee('value="0235:1001234567"', false)
            ->assertSee('<option value="DXB" selected', false));
    }

    /** A seller not registered for VAT gives its TIN instead of a TRN. */
    public function test_non_vat_seller_needs_tin(): void
    {
        $this->save($this->details(['trn' => '']))->assertSessionHasErrors('tin');

        $this->save($this->details(['trn' => '', 'tin' => '1009876543']))->assertSessionHasNoErrors();
        $this->assertSame('1009876543', ComplianceProfile::acrossTenants()
            ->where('org_id', $this->gulf->id)->firstOrFail()->settings['tin']);
    }

    public function test_saudi_shapes_are_not_asked(): void
    {
        $this->save($this->details(['building_number' => 'Tower B, 12th floor', 'postal_code' => '']))->assertSessionHasNoErrors();
        $this->save($this->details(['endpoint_id' => '1001234567', 'emirate' => 'Riyadh']))
            ->assertSessionHasErrors(['endpoint_id', 'emirate']);
    }

    public function test_member_cannot_change_details(): void
    {
        $member = $this->member($this->gulf, 'member');

        $this->asRequest(fn () => $this->actingAs($member)->withSession(['portal_org_id' => $this->gulf->id, '_token' => self::TOKEN])
            ->post('/portal/company', [...$this->details(), '_token' => self::TOKEN])
            ->assertForbidden());
    }

    private function open(User $user, Organization $organization): TestResponse
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $organization->id])->get('/portal/company');
    }

    /** @param  array<string, string>  $details */
    private function save(array $details): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['portal_org_id' => $this->gulf->id, '_token' => self::TOKEN])
            ->from('/portal/company')
            ->post('/portal/company', [...$details, '_token' => self::TOKEN]));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Gulf Trading LLC',
            'name_ar' => '',
            'trn' => '100123456700003',
            'tin' => '',
            'endpoint_id' => '0235:1001234567',
            'legal_registration_type' => 'TL',
            'legal_registration_id' => 'CN-1234567',
            'legal_registration_authority' => 'Dubai Economy and Tourism',
            'payment_account' => '',
            'street' => 'Al Wasl Road',
            'building_number' => '',
            'additional_street' => '',
            'district' => '',
            'city' => 'Dubai',
            'emirate' => 'DXB',
            'postal_code' => '',
        ], $overrides);
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
