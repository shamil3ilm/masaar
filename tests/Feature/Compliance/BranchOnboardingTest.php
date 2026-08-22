<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A taxpayer with more than one location onboards each of them separately.
 *
 * ZATCA issues a CSID per device, not per organization, so a branch has its own
 * certificate request built from its own device serial and address, and its own
 * credentials afterwards. Getting that wrong is quiet: the request is
 * well-formed either way, and the mistake surfaces as the authority rejecting
 * invoices from a branch that appears to be onboarded.
 *
 * The authority is faked because an OTP from their portal expires in an hour.
 * Everything before the network is real.
 */
class BranchOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $this->branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->organization->id,
            'name' => 'Jeddah Branch',
            'device_serial' => 'EGS-JED-0001',
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));

        $user = User::factory()->create(['email' => 'admin@masaar.test']);
        $user->organizations()->attach($this->organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'admin@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    public function test_a_branch_is_onboarded(): void
    {
        $this->zatcaAnswers();

        $this->request()->assertOk();

        $stored = app(BranchService::class)->getCredentials($this->branch->fresh(), 'ccsid');

        $this->assertNotNull($stored, 'The branch credential was not kept.');
        $this->assertSame('the-branch-secret', $stored['secret']);
    }

    /**
     * The request has to name this branch's device, not the organization. A
     * CSID issued against the wrong serial belongs to a device that is not the
     * one filing.
     */
    public function test_the_request_names_the_branch_device(): void
    {
        $this->zatcaAnswers();

        $this->request()->assertOk();

        Http::assertSent(function ($request) {
            $csr = base64_decode($request['csr'] ?? '');
            $subject = openssl_csr_get_subject($csr);

            // The branch's address lives in the subject alternative name, and
            // those bytes are in the DER — searching the PEM finds only the
            // base64 that encodes them.
            $der = (string) base64_decode(
                (string) preg_replace('/-----[^-]+-----|\s+/', '', $csr)
            );

            return $subject['CN'] === 'EGS-JED-0001'
                && $subject['OU'] === 'Jeddah Branch'
                && str_contains($der, 'Jeddah');
        });
    }

    public function test_the_branch_key_can_sign(): void
    {
        $this->zatcaAnswers();

        $this->request()->assertOk();

        $key = app(BranchService::class)
            ->getCredentials($this->branch->fresh(), 'ccsid')['privateKey'] ?? null;

        $this->assertNotEmpty($key, 'No private key was stored for the branch.');

        $signature = '';

        $this->assertTrue(
            openssl_sign('payload', $signature, $key, OPENSSL_ALGO_SHA256),
            'The branch private key cannot sign.'
        );
    }

    public function test_onboarding_advances_the_branch(): void
    {
        $this->zatcaAnswers();

        $this->request()->assertOk();

        $this->assertSame(
            Branch::STATUS_CSR_GENERATED,
            $this->branch->fresh()->onboarding_status
        );
    }

    /**
     * A second attempt would spend another OTP and replace credentials the
     * branch is already signing with.
     */
    public function test_an_onboarded_branch_is_not_redone(): void
    {
        $this->zatcaAnswers();
        $this->request()->assertOk();

        $this->request()->assertStatus(422);
    }

    public function test_an_unknown_branch_is_not_found(): void
    {
        $this->zatcaAnswers();

        $this->withToken($this->token)
            ->postJson('/api/organizations/branches/'.fake()->uuid().'/onboarding/ccsid', [
                'otp' => '123456',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * Another taxpayer's branch is not reachable by naming its id.
     */
    public function test_another_tenants_branch_is_not_found(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $theirs = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $rival->id,
            'name' => 'Rival Branch',
            'device_serial' => 'EGS-RIV-0001',
            'industry' => 'Retail',
            'street' => 'Some Road',
            'building_number' => '1111',
            'district' => 'Central',
            'city' => 'Dammam',
            'postal_code' => '31411',
        ]));

        $this->zatcaAnswers();

        $this->withToken($this->token)
            ->postJson("/api/organizations/branches/{$theirs->id}/onboarding/ccsid", [
                'otp' => '123456',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    private function zatcaAnswers(): void
    {
        Http::fake(['*/compliance' => Http::response([
            'requestID' => '9876543210',
            'binarySecurityToken' => base64_encode('THE-BRANCH-CERTIFICATE'),
            'secret' => 'the-branch-secret',
        ])]);
    }

    private function request(): TestResponse
    {
        return $this->withToken($this->token)
            ->postJson("/api/organizations/branches/{$this->branch->id}/onboarding/ccsid", [
                'otp' => '123456',
            ]);
    }
}
