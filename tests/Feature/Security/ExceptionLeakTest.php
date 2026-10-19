<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\CsidOnboarding;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * What a tenant is told when something inside fails.
 *
 * Onboarding and branch management caught every Exception and appended its
 * message to the response: a database error answered with the SQL and the
 * table, a filesystem one with the path where signing keys are kept, and all
 * of it as a 422 that told the caller the fault was theirs. The global
 * renderer already logs the detail and answers a generic 500, so they now
 * leave it to that; only the refusals meant for a person keep their words.
 */
class ExceptionLeakTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNAL = 'SQLSTATE[HY000]: /var/www/storage/app/credentials/keys';

    private Organization $organization;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $this->token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_onboarding_failure_hides_internals(): void
    {
        $this->mock(CsidOnboarding::class)
            ->shouldReceive('requestComplianceCsid')
            ->andThrow(new \RuntimeException(self::INTERNAL));

        $response = $this->withToken($this->token)->postJson('/api/compliance/onboarding/ccsid', [
            'otp' => '123456',
            'common_name' => 'EGS-1234567890',
            'serial_number' => '1-'.Brand::csrSolution().'|2-1.0|3-abc123',
        ]);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_branch_failure_hides_internals(): void
    {
        $this->mock(BranchService::class)
            ->shouldReceive('create')
            ->andThrow(new \RuntimeException(self::INTERNAL));

        $response = $this->withToken($this->token)->postJson('/api/organizations/branches', [
            'name' => 'Jeddah',
            'street' => 'Tahlia Street',
            'building_number' => '4321',
            'district' => 'Al Rawdah',
            'city' => 'Jeddah',
            'postal_code' => '23456',
        ]);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    /**
     * The refusal written for a person still reaches them.
     */
    public function test_a_used_branch_says_why(): void
    {
        $branch = app(BranchService::class)->create($this->organization, [
            'name' => 'Jeddah',
            'street' => 'Tahlia Street',
            'building_number' => '4321',
            'district' => 'Al Rawdah',
            'city' => 'Jeddah',
            'postal_code' => '23456',
        ]);

        Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-1',
            'type' => 'simplified',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $this->withToken($this->token)->deleteJson("/api/organizations/branches/{$branch->id}")
            ->assertUnprocessable()
            ->assertJsonPath('error.message', 'Cannot delete branch with existing invoices. Suspend instead.');

        $this->assertNotNull(Branch::withoutTenantScope(fn () => Branch::find($branch->id)));
    }
}
