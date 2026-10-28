<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Each till is onboarded with ZATCA on its own: its own OTP from the
 * Fatoora portal, a certificate request whose serial is the till's, and a
 * production certificate kept under the till (E-Invoicing Detailed
 * Guidelines p10: a CSID "uniquely identif[ies]" the unit; p39 and the FAQ
 * on p59: every device issuing under one VAT number is registered).
 */
class TillOnboardingTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private Branch $olaya;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Grocery',
            'country' => 'SA',
            'status' => 'active',
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $this->olaya = Branch::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'name' => 'Olaya Store',
            'device_serial' => 'EGS-OLAYA',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $issued = $this->selfSignedCredentials(serial: 7, days: 365);

        Http::fake([
            '*/compliance/invoices' => Http::response(['reportingStatus' => 'REPORTED', 'clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS']]),
            '*/compliance' => Http::response(['binarySecurityToken' => $this->selfSignedCredentials(serial: 9)['certificate'], 'secret' => 'ccsid-secret', 'requestID' => 1234567890]),
            '*/production/csids' => Http::response(['binarySecurityToken' => $issued['certificate'], 'secret' => 'till-secret', 'requestID' => 1234567891]),
            '*' => Http::response(['ok' => true]),
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_a_till_is_added_and_onboarded(): void
    {
        $unitId = (string) $this->withToken($this->token)
            ->postJson('/api/organizations/branches/'.$this->olaya->id.'/units', ['name' => 'Express lane'])
            ->assertCreated()
            ->json('data.unit.id');

        $this->withToken($this->token)->postJson('/api/organizations/units/'.$unitId.'/onboarding/ccsid', ['otp' => '123456'])->assertOk();
        $this->withToken($this->token)->postJson('/api/organizations/units/'.$unitId.'/onboarding/compliance-check')->assertOk()->assertJsonPath('data.passed', true);
        $this->withToken($this->token)->postJson('/api/organizations/units/'.$unitId.'/onboarding/pcsid')->assertOk();

        $unit = EgsUnit::acrossTenants()->findOrFail($unitId);
        $this->assertTrue($unit->isReady());
        $this->assertNotNull($unit->cert_expires_at);
        $this->assertSame('till-secret', app(CredentialStore::class)->get($this->acme->id, $unitId, CredentialStore::PCSID)['secret'] ?? null);
        $this->assertSame(1, CertificateVersion::acrossTenants()->where('device_id', $unitId)->count());

        // Its own OTP, and a request that names it by its own serial.
        Http::assertSent(function (Request $request) use ($unit): bool {
            if (! str_ends_with($request->url(), '/compliance')) {
                return false;
            }

            // The serial is in the subject alternative name, so in the DER.
            $csr = base64_decode((string) ($request['csr'] ?? ''));
            $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $csr));

            return $request->hasHeader('OTP', '123456')
                && str_contains($der, '3-'.$unit->id)
                && openssl_csr_get_subject($csr)['CN'] === 'EGS-T02-ACMEGROCERY';
        });

        // The branch's first till is untouched.
        $this->assertSame(Branch::STATUS_PENDING, EgsUnit::acrossTenants()->find($this->olaya->id)->onboarding_status);
    }

    public function test_the_api_lists_every_till(): void
    {
        $this->withToken($this->token)->postJson('/api/organizations/branches/'.$this->olaya->id.'/units')->assertCreated();

        $units = $this->withToken($this->token)->getJson('/api/organizations/units')->assertOk()->json('data.units');

        $this->assertCount(3, $units);
        $this->assertTrue($units[0]['head_office']);
        $this->assertSame([1, 2], [$units[1]['till_number'], $units[2]['till_number']]);
    }

    public function test_setup_lists_tills_to_connect(): void
    {
        $steps = collect(app(SetupChecklist::class)->steps($this->acme->fresh()));

        $till = $steps->firstWhere('key', 'zatca-till-'.$this->olaya->id);
        $this->assertNotNull($till);
        $this->assertFalse($till['required']);
        $this->assertFalse($till['done']);
    }

    public function test_a_member_cannot_add_a_till(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'member'])->fromUser($user);

        $this->withToken($token)->postJson('/api/organizations/branches/'.$this->olaya->id.'/units')->assertForbidden();
    }
}
