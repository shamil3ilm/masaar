<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Connecting to ZATCA from the portal.
 *
 * The three onboarding steps were reachable only over the API, so a business
 * with no developer could not connect at all.
 */
class ZatcaWizardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
    }

    public function test_the_otp_gets_a_compliance_certificate(): void
    {
        Http::fake(['*/compliance' => Http::response([
            'requestID' => '1234567890',
            'binarySecurityToken' => base64_encode('THE-CCSID-CERTIFICATE'),
            'secret' => 'the-ccsid-secret',
        ])]);

        $this->asMember('admin')->get('/portal/zatca')->assertOk()->assertSee('Compliance certificate');

        $this->asMember('admin')->post('/portal/zatca/ccsid', [
            'otp' => '123456',
            'common_name' => 'EGS1-ACME',
            'organization_unit' => 'Main Branch',
            'industry' => 'Retail',
        ])->assertSessionHas('success');

        $stored = app(CredentialStore::class)->get($this->acme->id, null, CredentialStore::CCSID);

        $this->assertSame('the-ccsid-secret', $stored['secret'] ?? null);
        Http::assertSent(fn ($request) => $request->hasHeader('OTP', '123456'));
    }

    public function test_the_last_step_connects_the_company(): void
    {
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::CCSID, [
            'ccsid' => base64_encode('cert'),
            'secret' => 's',
            'requestId' => '1',
            'privateKey' => 'key',
        ]);

        Http::fake(['*/production/csids' => Http::response([
            'binarySecurityToken' => base64_encode('THE-PCSID'),
            'secret' => 'the-pcsid-secret',
        ])]);

        $this->asMember('admin')->post('/portal/zatca/production')->assertSessionHas('success');

        $this->assertTrue((bool) ($this->acme->fresh()->compliance_profile['zatca_onboarded'] ?? false));
        $this->asMember('admin')->get('/portal/zatca')->assertSee('is onboarded with ZATCA');
    }

    /**
     * Refused before the OTP is spent on a certificate it could not use.
     */
    public function test_a_company_under_review_waits(): void
    {
        Http::fake();
        $this->acme->forceFill(['status' => Organization::STATUS_PENDING_REVIEW])->save();

        $this->asMember('admin')->get('/portal/zatca')->assertOk()->assertSee('being reviewed');
        $this->asMember('admin')->post('/portal/zatca/ccsid', ['otp' => '123456', 'common_name' => 'EGS1'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_only_an_admin_connects(): void
    {
        $this->asMember('member')->get('/portal/zatca')->assertForbidden();
        $this->asMember('member')->post('/portal/zatca/production')->assertForbidden();
    }

    public function test_incomplete_details_are_sent_to_company(): void
    {
        $this->acme->forceFill(['district' => null])->save();

        $this->asMember('admin')->get('/portal/zatca')
            ->assertOk()
            ->assertSee(route('portal.company.edit'), false);
    }

    private function asMember(string $role): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
