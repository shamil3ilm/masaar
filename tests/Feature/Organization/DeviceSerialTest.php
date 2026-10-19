<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A device serial names a unit within its taxpayer, not across the platform.
 *
 * Serials were unique platform-wide, and the generated one is predictable -
 * the VAT number printed on every invoice, a branch count, a fixed suffix. One
 * tenant could register another's next serial and that organization could no
 * longer add a branch. It also happened without anyone trying: every
 * organization without a VAT number generates the same first serial, so only
 * the first of them could create a branch at all.
 */
class DeviceSerialTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_tenant_cannot_claim_ours(): void
    {
        $acme = $this->organization('Acme', '300000000000003');
        $rival = $this->organization('Rival', '311111111111113');

        $ours = '1-300000000000003|2-001|3-'.Str::upper(Str::slug((string) config('app.name'), ''));
        $this->createBranch($rival, ['device_serial' => $ours])->assertCreated();

        $this->createBranch($acme)->assertCreated();

        $this->assertSame(
            $ours,
            Branch::withoutTenantScope(fn () => Branch::where('org_id', $acme->id)->value('device_serial')),
        );
    }

    public function test_unregistered_organizations_coexist(): void
    {
        $this->createBranch($this->organization('Acme', null))->assertCreated();
        $this->createBranch($this->organization('Globex', null))->assertCreated();
    }

    public function test_a_serial_is_unique_within_us(): void
    {
        $acme = $this->organization('Acme', '300000000000003');

        $this->createBranch($acme, ['device_serial' => 'EGS-1'])->assertCreated();
        $this->createBranch($acme, ['device_serial' => 'EGS-1'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['device_serial']]]);
    }

    private function organization(string $name, ?string $vatNumber): Organization
    {
        return Organization::create([
            'name' => $name,
            'country' => 'SA',
            'vat_number' => $vatNumber,
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function createBranch(Organization $organization, array $overrides = []): TestResponse
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user);

        return $this->withToken($token)->postJson('/api/organizations/branches', [
            'name' => 'Jeddah',
            'street' => 'Tahlia Street',
            'building_number' => '4321',
            'district' => 'Al Rawdah',
            'city' => 'Jeddah',
            'postal_code' => '23456',
            ...$overrides,
        ]);
    }
}
