<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listing a taxpayer's branches.
 *
 * This endpoint could not be reached. It was declared after
 * GET /api/organizations/{organization}, and Laravel matches in declaration
 * order, so the request was answered by the organization resource with the
 * word 'branches' as the id - a 404 that reads like an empty account rather
 * than a route nothing could reach.
 *
 * Nothing caught it because nothing listed branches. Every test that touched
 * this prefix posted to a nested path - /branches/{branch}/onboarding/ccsid
 * and its siblings - which matched correctly, because no
 * POST /api/organizations/{organization} exists to catch them. The index was
 * the only route under the prefix that collided, and it was the only one
 * never called.
 *
 * RouteShadowTest asserts the ordering across the whole table. This asserts
 * the endpoint.
 */
class BranchListTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create(['email' => 'admin@masaar.test']);
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'admin@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    public function test_branches_can_be_listed(): void
    {
        $this->branch('Jeddah Branch', 'EGS-JED-0001');

        $this->withToken($this->token)
            ->getJson('/api/organizations/branches')
            ->assertOk()
            ->assertSee('Jeddah Branch');
    }

    /**
     * And the word 'branches' is not read as an organization id.
     *
     * This is the shape of the failure: the request reached
     * OrganizationController::show, looked for an organization whose id was
     * the string 'branches', found none, and answered 404.
     */
    public function test_branches_is_not_an_organization_id(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/organizations/branches')
            ->assertOk();
    }

    private function branch(string $name, string $serial): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => $name,
            'device_serial' => $serial,
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));
    }
}
