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
            ->assertJsonValidationErrors(['org_id'], 'error.details');

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

    /**
     * The admin licence API answers in the envelope the rest of the API uses.
     * A licence's own refusal is a 409 with its words; it was a 400 in a
     * shape of its own, from a catch that would have returned a database
     * error's SQL just the same.
     */
    public function test_a_refusal_uses_the_envelope(): void
    {
        $licence = $this->licence();

        $this->asPlatformAdmin()
            ->postJson("/api/admin/licenses/{$licence->id}/activate")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACTIVATION_FAILED')
            ->assertJsonPath('error.message', 'License is not pending activation. Current status: active');
    }

    /**
     * days arrived as the string JSON allows and met an int parameter under
     * strict types: a 500. month[] did the same on the usage report.
     */
    public function test_loose_input_is_handled(): void
    {
        $licence = $this->licence();

        $this->asPlatformAdmin()->postJson("/api/admin/licenses/{$licence->id}/extend", ['days' => '30'])->assertOk();
        $this->asPlatformAdmin()->getJson("/api/admin/licenses/{$licence->id}/usage?month[]=2026-01")->assertUnprocessable();
    }

    private function licence(): License
    {
        return License::createWithCredentials([
            'organization_name' => 'Acme',
            'contact_email' => 'erp@acme.test',
            'tier' => 'professional',
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ])['license'];
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
