<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Step one of ZATCA onboarding, driven over HTTP with the authority faked.
 *
 * Everything up to the network is real: the request is authenticated, the
 * tenant comes from the token, a certificate request is generated with OpenSSL,
 * and the credential that comes back is encrypted to disk. Only ZATCA itself is
 * replaced, because an OTP from their portal expires in an hour.
 *
 * This could not have been written before today. Certificate requests could not
 * be generated at all, and no JWT request carried a tenant, so the endpoint
 * failed twice over before reaching anything worth asserting.
 */
class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

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

        $user = User::factory()->create(['email' => 'onboarder@masaar.test']);
        $user->organizations()->attach($this->organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'onboarder@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    public function test_csid_is_obtained_and_kept(): void
    {
        Http::fake(['*/compliance' => Http::response([
            'requestID' => '1234567890',
            'binarySecurityToken' => base64_encode('THE-CCSID-CERTIFICATE'),
            'secret' => 'the-ccsid-secret',
        ])]);

        $this->request()->assertOk();

        $stored = app(CredentialStore::class)
            ->get($this->organization->id, null, CredentialStore::CCSID);

        $this->assertNotNull($stored, 'The credential was not kept.');
        $this->assertSame('the-ccsid-secret', $stored['secret']);
    }

    /**
     * The key the platform will sign with, and the reason the whole flow
     * matters. It used to come back empty: openssl_pkey_export() was called
     * without checking its result, and it leaves its output untouched when it
     * fails. Onboarding would complete against a key that cannot sign.
     */
    public function test_the_stored_key_can_sign(): void
    {
        Http::fake(['*/compliance' => Http::response([
            'requestID' => '1',
            'binarySecurityToken' => base64_encode('cert'),
            'secret' => 's',
        ])]);

        $this->request()->assertOk();

        $key = app(CredentialStore::class)
            ->get($this->organization->id, null, CredentialStore::CCSID)['privateKey'] ?? null;

        $this->assertNotEmpty($key, 'No private key was stored.');

        $signature = '';

        $this->assertTrue(
            openssl_sign('payload', $signature, $key, OPENSSL_ALGO_SHA256),
            'The stored private key cannot sign.'
        );
    }

    /**
     * ZATCA is sent a real certificate request, base64-encoded, not a
     * placeholder.
     */
    public function test_zatca_receives_a_real_request(): void
    {
        Http::fake(['*/compliance' => Http::response([
            'requestID' => '1',
            'binarySecurityToken' => base64_encode('cert'),
            'secret' => 's',
        ])]);

        $this->request()->assertOk();

        Http::assertSent(function ($request) {
            $csr = base64_decode($request['csr'] ?? '');

            return str_contains($csr, 'BEGIN CERTIFICATE REQUEST')
                && openssl_csr_get_subject($csr)['CN'] === 'EGS-1234567890';
        });
    }

    /**
     * ZATCA refusing is the taxpayer's problem to see, not a 500.
     */
    public function test_a_refusal_is_reported(): void
    {
        Http::fake(['*/compliance' => Http::response(['errors' => ['Invalid OTP']], 400)]);

        $this->request()->assertStatus(422);

        $this->assertNull(
            app(CredentialStore::class)->get($this->organization->id, null, CredentialStore::CCSID),
            'A credential was kept for a request the authority refused.'
        );
    }

    /**
     * The request is built from the organization's own registration details,
     * so an incomplete profile is refused before an OTP is spent.
     */
    public function test_an_incomplete_profile_is_refused(): void
    {
        $this->organization->update(['vat_number' => null]);

        $this->request()->assertStatus(422);

        Http::assertNothingSent();
    }

    private function request(): TestResponse
    {
        return $this->withToken($this->token)
            ->postJson('/api/compliance/onboarding/ccsid', [
                'otp' => '123456',
                'common_name' => 'EGS-1234567890',
                'serial_number' => '1-Masaar|2-1.0|3-abc123',
            ]);
    }
}
