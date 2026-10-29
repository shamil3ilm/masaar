<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Finishing a production CSID step whose last write did not land.
 *
 * requestPcsid asked the authority for a certificate, wrote it to the
 * credential store, and then marked the branch active. The file and the row
 * cannot be one transaction, so dying in between left a branch holding a
 * valid production certificate on disk while onboarding_status stayed
 * compliance_passed - isFatooraReady() false, so Submitter refused every
 * invoice from it, while the admin console showed it as not yet onboarded.
 *
 * Recovery was the expensive part. Running the step again called ZATCA for a
 * second certificate, and the OTP that bought the first one was already
 * spent, so the documented remedy was to obtain another from the ZATCA
 * Fatoora portal - a human, in a browser, for a certificate that was sitting
 * unused on the disk.
 *
 * So the step now notices. A PCSID already in the store means this ran and
 * only its last write failed, and finishing the row is the whole recovery.
 */
class PcsidRetryTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

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
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $this->branch = app(BranchService::class)->create($this->organization, [
            'name' => 'Riyadh Gallery',
            'street' => 'King Fahd Road',
            'building_number' => '4321',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();

        // Step two is done: the compliance CSID is stored and the checks
        // passed, which is where requestPcsid picks up.
        app(CredentialStore::class)->put($this->branch->org_id, $this->branch->id, CredentialStore::CCSID, [
            'ccsid' => base64_encode('THE-COMPLIANCE-CERTIFICATE'),
            'secret' => 'the-branch-secret',
            'requestId' => '9876543210',
            'privateKey' => $credentials['privateKey'],
        ]);

        $this->branch->update(['onboarding_status' => Branch::STATUS_COMPLIANCE_PASSED]);

        // And step three got as far as writing the certificate.
        app(CredentialStore::class)->put($this->branch->org_id, $this->branch->id, CredentialStore::PCSID, [
            'pcsid' => $credentials['certificate'],
            'privateKey' => $credentials['privateKey'],
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_a_stored_certificate_finishes_the_step(): void
    {
        Http::fake();

        $this->request()->assertOk();

        $this->assertSame(
            Branch::STATUS_ACTIVE,
            $this->branch->fresh()->onboarding_status,
            'the branch still reads as not onboarded, so it cannot invoice'
        );
    }

    /**
     * And it costs no OTP, because it asks the authority for nothing.
     */
    public function test_it_asks_the_authority_for_nothing(): void
    {
        Http::fake();

        $this->request()->assertOk();

        Http::assertNothingSent();
    }

    /**
     * The expiry comes off the certificate that was already stored, so the
     * branch is not left active with no idea when it lapses.
     */
    public function test_the_expiry_comes_from_the_certificate(): void
    {
        Http::fake();

        $this->request()->assertOk();

        $this->assertNotNull(
            $this->branch->fresh()->cert_expires_at,
            'the branch is active with no expiry, so nothing will warn before it lapses'
        );
    }

    private function request(): TestResponse
    {
        return $this->withToken($this->token)
            ->postJson("/api/organizations/branches/{$this->branch->id}/onboarding/pcsid");
    }
}
