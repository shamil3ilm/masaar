<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
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
 * Renewing any till's certificate from the portal, through the guarded flow
 * head office's renewal has: what the till's current certificate signed and
 * ZATCA does not hold yet is listed first, one OTP runs all three steps, the
 * new version becomes the active one and the old is superseded, and a
 * renewal with documents unfiled needs a reason, which is audited. A till
 * not yet connected is connected on the same page with its own OTP.
 */
class PortalTillRenewalTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private Branch $olaya;

    private EgsUnit $lane;

    private bool $checksPass = true;

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

        $this->olaya = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id, 'name' => 'Olaya', 'device_serial' => 'EGS-OLAYA',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]));
        $this->lane = app(Tills::class)->add($this->olaya, 'Express lane');
        $this->lane->forceFill(['onboarding_status' => 'active'])->save();

        foreach ([null, $this->lane->id] as $unit) {
            $keys = $this->selfSignedCredentials(serial: 1);
            app(CredentialStore::class)->put($this->acme->id, $unit, CredentialStore::PCSID, [
                'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'current-secret',
            ]);
        }

        $renewed = $this->selfSignedCredentials(serial: 2, days: 730);

        Http::fake([
            '*/compliance/invoices' => fn () => $this->checksPass
                ? Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])
                : Http::response(['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['message' => 'BR-KSA-01']]]], 400),
            '*/compliance' => Http::response(['binarySecurityToken' => base64_encode('NEW-CCSID'), 'secret' => 'ccsid-secret', 'requestID' => 1234567890]),
            '*/production/csids' => Http::response(['binarySecurityToken' => $renewed['certificate'], 'secret' => 'renewed-secret', 'requestID' => 1234567891]),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_the_panel_links_each_tills_renewal(): void
    {
        $this->asMember('admin')->get(route('portal.zatca.certificates'))
            ->assertOk()
            ->assertSee('Express lane')
            ->assertSee(route('portal.zatca.tills.certificate', $this->lane->id), false)
            ->assertSee(route('portal.zatca.tills.certificate', $this->olaya->id), false);
    }

    public function test_an_otp_renews_the_till(): void
    {
        $this->asMember('admin')->get(route('portal.zatca.tills.certificate', $this->lane->id))->assertOk()->assertSee('OTP');

        $this->renew()->assertRedirect(route('portal.zatca.certificates'))->assertSessionHas('success');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('OTP', '123456'));
        $versions = $this->versions();
        $this->assertSame([CertificateVersion::SUPERSEDED, CertificateVersion::ACTIVE], $versions->pluck('status')->all());
        $this->assertSame('renewed-secret', app(CredentialStore::class)->get($this->acme->id, $this->lane->id, CredentialStore::PCSID)['secret'] ?? null);
        // Head office's certificate is untouched.
        $this->assertSame('current-secret', app(CredentialStore::class)->get($this->acme->id, null, CredentialStore::PCSID)['secret'] ?? null);
    }

    public function test_unfiled_documents_hold_the_renewal(): void
    {
        $this->issued();

        $this->asMember('admin')->get(route('portal.zatca.tills.certificate', $this->lane->id))
            ->assertOk()
            ->assertSee('LANE-WAITING')
            ->assertSee('renewal_override_reason', false);

        $this->renew()->assertSessionHas('error');

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/compliance'));
        $this->assertCount(1, $this->versions());
    }

    public function test_an_override_reason_is_audited(): void
    {
        $this->issued();

        $this->renew(['renewal_override_reason' => 'The till was stolen; renewing at once.'])->assertSessionHas('success');

        $logged = DB::table('audit_logs')->where('action', 'zatca.renewal.override')->first();
        $this->assertNotNull($logged);
        $this->assertStringContainsString('LANE-WAITING', (string) $logged->metadata);
        $this->assertStringContainsString($this->lane->id, (string) $logged->metadata);
        $this->assertSame(CertificateVersion::ACTIVE, $this->versions()->last()->status);
    }

    public function test_refused_checks_finish_without_otp(): void
    {
        $this->checksPass = false;
        $this->renew()->assertSessionHas('error');
        $this->assertCount(1, $this->versions());

        $this->asMember('admin')->get(route('portal.zatca.tills.certificate', $this->lane->id))
            ->assertOk()
            ->assertSee(route('portal.zatca.tills.certificate.finish', $this->lane->id), false);

        $this->checksPass = true;
        $this->asMember('admin')->post(route('portal.zatca.tills.certificate.finish', $this->lane->id))
            ->assertRedirect(route('portal.zatca.certificates'));

        $this->assertSame([CertificateVersion::SUPERSEDED, CertificateVersion::ACTIVE], $this->versions()->pluck('status')->all());
    }

    public function test_a_new_till_is_connected(): void
    {
        $this->asMember('admin')->get(route('portal.zatca.tills.certificate', $this->olaya->id))
            ->assertOk()
            ->assertSee(__('Connect the till'));

        $this->asMember('admin')->post(route('portal.zatca.tills.certificate.renew', $this->olaya->id), ['otp' => '654321'])
            ->assertRedirect(route('portal.zatca.certificates'));

        $this->assertTrue(EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->find($this->olaya->id))->isReady());
        $this->assertSame('renewed-secret', app(CredentialStore::class)->get($this->acme->id, $this->olaya->id, CredentialStore::PCSID)['secret'] ?? null);
    }

    public function test_only_an_administrator_renews(): void
    {
        $this->asMember('member')->get(route('portal.zatca.tills.certificate', $this->lane->id))->assertForbidden();
        $this->asMember('member')->post(route('portal.zatca.tills.certificate.renew', $this->lane->id), ['otp' => '123456'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_page_reads_in_arabic(): void
    {
        $this->asMember('admin', 'ar')->get(route('portal.zatca.tills.certificate', $this->lane->id))
            ->assertOk()
            ->assertSee('تجديد شهادة');
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function renew(array $extra = []): TestResponse
    {
        return $this->asMember('admin')->post(route('portal.zatca.tills.certificate.renew', $this->lane->id), ['otp' => '123456', ...$extra]);
    }

    /**
     * @return Collection<int, CertificateVersion>
     */
    private function versions(): Collection
    {
        return CertificateVersion::withoutTenantScope(fn () => CertificateVersion::query()
            ->where('device_id', $this->lane->id)->orderBy('version')->get());
    }

    private function issued(): void
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'branch_id' => $this->olaya->id,
            'invoice_number' => 'LANE-WAITING',
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
        $invoice->forceFill(['device_id' => $this->lane->id])->save();
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
