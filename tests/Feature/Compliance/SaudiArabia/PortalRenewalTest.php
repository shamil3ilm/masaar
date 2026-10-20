<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Renewing the organization's ZATCA certificate from the portal.
 *
 * Every portal onboarding step is disabled once the organization is
 * onboarded, so renewal was reachable only over the API. Reached from the
 * Certificates panel: a new OTP, the compliance checks, the production
 * certificate - and the new version ends active, the old one superseded.
 * While documents the current certificate signed are unfiled, RenewalGuard
 * lists them and the renewal needs a reason, which is audited.
 */
class PortalRenewalTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private bool $checksPass = true;

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
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $current = $this->selfSignedCredentials(serial: 1);
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $current['privateKey'], 'pcsid' => $current['certificate'], 'secret' => 'current-secret',
        ]);

        $renewed = $this->selfSignedCredentials(serial: 2, days: 730);

        Http::fake([
            '*/compliance/invoices' => fn () => $this->checksPass
                ? Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])
                : Http::response(['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['message' => 'BR-KSA-01']]]], 400),
            '*/compliance' => Http::response(['binarySecurityToken' => $this->selfSignedCredentials(serial: 9)['certificate'], 'secret' => 'ccsid-secret', 'requestID' => 1234567890]),
            '*/production/csids' => Http::response(['binarySecurityToken' => $renewed['certificate'], 'secret' => 'renewed-secret', 'requestID' => 1234567891]),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_the_panel_links_the_renewal(): void
    {
        $this->asMember('admin')->get('/portal/zatca/certificates')
            ->assertOk()
            ->assertSee(route('portal.zatca.renew'), false);
    }

    public function test_an_otp_renews_to_active_version(): void
    {
        $this->asMember('admin')->get('/portal/zatca/certificates/renew')->assertOk()->assertSee('OTP');

        $this->renew()
            ->assertRedirect(route('portal.zatca.certificates'))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('OTP', '123456'));
        $versions = $this->versions();
        $this->assertCount(2, $versions);
        $this->assertSame(CertificateVersion::SUPERSEDED, $versions[0]->status);
        $this->assertSame(CertificateVersion::ACTIVE, $versions[1]->status);
        $this->assertSame('renewed-secret', app(CredentialStore::class)->get($this->acme->id, null, CredentialStore::PCSID)['secret'] ?? null);
    }

    /**
     * Listed before the OTP is asked for, and refused without a reason
     * before it is spent.
     */
    public function test_unfiled_documents_hold_the_renewal(): void
    {
        $this->issued('INV-WAITING-1');

        $this->asMember('admin')->get('/portal/zatca/certificates/renew')
            ->assertOk()
            ->assertSee('INV-WAITING-1')
            ->assertSee('renewal_override_reason', false);

        $this->renew()->assertSessionHas('error');

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/compliance'));
        $this->assertCount(1, $this->versions());
    }

    public function test_an_override_reason_is_audited(): void
    {
        $this->issued('INV-WAITING-2');

        $this->renew(['renewal_override_reason' => 'The device key was exposed; renewing at once.'])
            ->assertSessionHas('success');

        $logged = DB::table('audit_logs')->where('action', 'zatca.renewal.override')->first();
        $this->assertNotNull($logged);
        $this->assertStringContainsString('INV-WAITING-2', (string) $logged->metadata);
        $this->assertSame(CertificateVersion::ACTIVE, $this->versions()->last()->status);
        $this->assertCount(2, $this->versions());
    }

    /**
     * The OTP is spent on the compliance certificate; refused checks leave
     * the old certificate signing, and the renewal is finished without
     * another OTP.
     */
    public function test_refused_checks_finish_without_otp(): void
    {
        $this->checksPass = false;

        $this->renew()->assertSessionHas('error');

        $this->assertCount(1, $this->versions());
        $this->assertSame(CertificateVersion::ACTIVE, $this->versions()[0]->status);
        $this->asMember('admin')->get('/portal/zatca/certificates/renew')
            ->assertOk()
            ->assertSee(route('portal.zatca.renew.finish'), false);

        $this->checksPass = true;
        $this->asMember('admin')->post('/portal/zatca/certificates/renew/finish')
            ->assertRedirect(route('portal.zatca.certificates'));

        $this->assertSame(CertificateVersion::ACTIVE, $this->versions()->last()->status);
        $this->assertCount(2, $this->versions());
    }

    public function test_only_an_administrator_renews(): void
    {
        $this->asMember('member')->get('/portal/zatca/certificates/renew')->assertForbidden();
        $this->asMember('member')->post('/portal/zatca/certificates/renew', ['otp' => '123456', 'common_name' => 'EGS1'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_renewal_reads_in_arabic(): void
    {
        $this->asMember('admin', 'ar')->get('/portal/zatca/certificates/renew')
            ->assertOk()
            ->assertSee('تجديد الشهادة');
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function renew(array $extra = []): TestResponse
    {
        return $this->asMember('admin')->post('/portal/zatca/certificates/renew', [
            'otp' => '123456',
            'common_name' => 'EGS1-ACME',
            'organization_unit' => 'Main Branch',
            'industry' => 'Retail',
            ...$extra,
        ]);
    }

    /**
     * @return Collection<int, CertificateVersion>
     */
    private function versions(): Collection
    {
        return CertificateVersion::withoutTenantScope(fn () => CertificateVersion::query()
            ->where('device_id', $this->acme->id)->orderBy('version')->get());
    }

    private function issued(string $number): void
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);
    }

    private function asMember(string $role, string $locale = 'en'): static
    {
        $user = User::factory()->create(['locale' => $locale]);
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
