<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use App\Support\Brand;
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

        $ours = '1-300000000000003|2-001|3-'.Str::upper(Str::slug(Brand::csrSolution(), ''));
        $this->createBranch($rival, ['device_serial' => $ours])->assertCreated();

        $this->createBranch($acme)->assertCreated();

        $branch = Branch::withoutTenantScope(fn () => Branch::where('org_id', $acme->id)->firstOrFail());
        $this->assertSame(Tills::serialFor((string) $branch->id), $branch->device_serial);
    }

    /**
     * A new branch's first till is named as every till is (Tills::serialFor):
     * the solution, its version and the unit's own id, signed into its CSR.
     */
    public function test_new_branch_serial_is_a_tills(): void
    {
        $acme = $this->organization('Acme', '300000000000003');

        $id = $this->createBranch($acme)->assertCreated()->json('data.branch.id') ?? $this->createdId($acme);
        $branch = Branch::withoutTenantScope(fn () => Branch::query()->findOrFail($id));

        $this->assertSame(Tills::serialFor((string) $branch->id), $branch->device_serial);
        $this->assertSame($branch->device_serial, app(Tills::class)->find($acme->id, $branch->id)?->egs_serial);
        // Its certificate request carries it whole, as a till's does.
        $this->assertSame($branch->device_serial, $branch->getCsrData()->serialNumber);
    }

    private function createdId(Organization $organization): string
    {
        return (string) Branch::withoutTenantScope(fn () => Branch::where('org_id', $organization->id)->latest('created_at')->value('id'));
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

    /**
     * A new certificate request names the solution config/brand.php names,
     * so a rename reaches the next serial; a serial already stored stays.
     */
    public function test_the_solution_is_the_brands(): void
    {
        config(['brand.csr_solution' => 'Nibras Cloud']);

        $this->assertSame('1-nibras-cloud|2-1.0|3-unit-1', Branch::generateDeviceSerial('unit-1'));
        $this->assertSame('1-nibras-cloud|2-1.0|3-unit-1', Tills::serialFor('unit-1'));
        $this->assertStringStartsWith('1-nibras-cloud|2-1.0|3-', app(OrganizationOnboarding::class)->deviceSerial());
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
