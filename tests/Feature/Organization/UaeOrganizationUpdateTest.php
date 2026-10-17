<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\UaeSellerDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * PUT /api/organizations/{id} for a UAE organization saves the UAE seller
 * details it validates, as PUT /api/compliance/ae/profile does, rather than
 * validating them and dropping them.
 */
class UaeOrganizationUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_uae_seller_fields_are_saved(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE', 'street' => 'Sheikh Zayed Road', 'city' => 'Dubai']);

        $this->api($gulf)->putJson("/api/organizations/{$gulf->id}", [
            'name' => 'Gulf Trading FZE',
            'emirate' => 'SHJ',
            'trn' => '100123456700003',
            'endpoint_id' => '0235:1001234567',
            'legal_registration_type' => 'TL',
            'legal_registration_id' => 'CN-1234567',
            'legal_registration_authority' => 'Sharjah Economic Development Department',
            'prices_include_vat' => true,
        ])->assertOk()->assertJsonPath('data.organization.name', 'Gulf Trading FZE');

        $gulf = $gulf->fresh();
        $settings = app(UaeSellerDetails::class)->current($gulf);
        $this->assertSame(['SHJ', '100123456700003', '1001234567', '0235:1001234567', 'TL'], [
            $settings['emirate'], $settings['trn'], $settings['tin'], $settings['endpoint_id'], $settings['legal_registration_type'],
        ]);
        $this->assertTrue((bool) $gulf->prices_include_vat);
        $this->assertSame('Sheikh Zayed Road', $gulf->street);

        $this->api($gulf)->putJson("/api/organizations/{$gulf->id}", ['endpoint_id' => '1001234567'])
            ->assertUnprocessable();
    }

    /** Only the name: nothing UAE-specific to save, no profile made. */
    public function test_name_alone_makes_no_profile(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE']);

        $this->api($gulf)->putJson("/api/organizations/{$gulf->id}", ['name' => 'Gulf Trading FZE'])->assertOk();

        $this->assertSame('Gulf Trading FZE', $gulf->fresh()->name);
        $this->assertSame(0, $gulf->complianceProfiles()->count());
    }

    private function api(Organization $organization): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        return $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user));
    }
}
