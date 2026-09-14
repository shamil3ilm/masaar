<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A licence issued through the admin API has to reach a tenant.
 *
 * ValidateLicense scopes every partner call to the licence's org_id. The
 * endpoint did not accept one and the service did not pass it, so each licence
 * it issued authenticated and then acted for no organization.
 */
class LicenseStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_licence_needs_its_organization(): void
    {
        $this->asPlatformAdmin()
            ->postJson('/api/admin/licenses', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['org_id'], 'errors');

        $this->assertSame(0, License::count());
    }

    public function test_credentials_act_for_the_organization(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $credentials = $this->asPlatformAdmin()
            ->postJson('/api/admin/licenses', $this->payload(['org_id' => $organization->id, 'status' => 'active']))
            ->assertCreated()
            ->json('data.credentials');

        $this->assertSame($organization->id, License::sole()->org_id);

        $this->withHeaders([
            'X-API-Key' => $credentials['api_key'],
            'X-API-Secret' => $credentials['api_secret'],
        ])->getJson('/api/v1/dashboard/health')->assertOk();
    }

    private function asPlatformAdmin(): static
    {
        return $this->withToken(JWTAuth::fromUser(User::factory()->platformAdmin()->create()));
    }

    private function payload(array $overrides = []): array
    {
        return [
            'organization_name' => 'Acme',
            'contact_email' => 'erp@acme.test',
            'tier' => 'professional',
            ...$overrides,
        ];
    }
}
