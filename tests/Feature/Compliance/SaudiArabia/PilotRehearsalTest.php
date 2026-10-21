<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitch;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\Check;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\Rehearsal;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\RehearsalOrganization;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\TlvEncoder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * platform:rehearse, with ZATCA's sandbox played by a fake that answers as
 * the developer portal does: a certificate for the request's own key, all
 * six compliance documents passed, every invoice cleared or reported.
 *
 * Everything before the network is the platform's own code, so the journey
 * a pilot takes - OTP to production certificate, then seven documents - is
 * exercised end to end.
 */
class PilotRehearsalTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private User $admin;

    /** @var array{key: \OpenSSLAsymmetricKey, certificate: \OpenSSLCertificate} */
    private array $authority;

    /** The certificate the fake issued for the last request's key. */
    private string $issued = '';

    /** How the fake answers a clearance or a report. */
    private string $verdict = 'PASS';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        Sleep::fake();
        config([
            'fatoora.signing.disk' => 'creds',
            'fatoora.environment' => 'simulation',
            'fatoora.endpoints.sandbox' => 'https://zatca.test/sandbox',
            'fatoora.endpoints.simulation' => 'https://zatca.test/simulation',
        ]);

        $this->admin = User::factory()->platformAdmin()->create(['email' => 'ops@example.test']);
        $this->authority = $this->authority();

        Http::fake(fn (Request $request) => $this->answer($request));
    }

    public function test_sandbox_journey_passes_every_step(): void
    {
        $organization = app(RehearsalOrganization::class)->prepare((string) $this->admin->id);

        $checks = app(Rehearsal::class)->run($organization, 'test', 0);
        $keys = array_map(fn (Check $check): string => $check->key, $checks);

        $this->assertSame(['profile', 'ccsid', 'compliance', 'pcsid', 'simplified', 'standard', 'credit_note', 'debit_note', 'zero_rated', 'discount', 'foreign_currency', 'pdf', 'qr', 'chain', 'offline', 'audit'], $keys);

        foreach ($checks as $check) {
            $this->assertTrue($check->passed, "{$check->name}: {$check->detail}");
        }

        $this->assertSame(7, Invoice::where('org_id', $organization->id)->whereNotNull('hash')->count());
    }

    public function test_command_reports_and_suspends_afterwards(): void
    {
        $this->artisan('platform:rehearse', ['--admin' => 'ops@example.test'])
            ->expectsOutputToContain('PASS  Simplified invoice, reported')
            ->expectsOutputToContain('suspended until the next rehearsal')
            ->assertSuccessful();

        $organization = app(RehearsalOrganization::class)->find();

        $this->assertTrue($organization->isSuspended());
        $this->assertSame(RehearsalOrganization::SUSPENSION_REASON, $organization->suspension_reason);
        $this->assertSame(7, Invoice::where('org_id', $organization->id)->whereNotNull('hash')->count(), 'Rehearsal records are kept.');
    }

    public function test_second_run_reuses_the_organization(): void
    {
        Artisan::call('platform:rehearse', ['--admin' => 'ops@example.test']);
        Artisan::call('platform:rehearse', ['--admin' => 'ops@example.test', '--keep' => true]);

        $this->assertSame(1, Organization::where('vat_number', RehearsalOrganization::VAT_NUMBER)->count());
        $this->assertSame(Organization::STATUS_ACTIVE, app(RehearsalOrganization::class)->find()->status);
    }

    public function test_a_rejection_fails_the_run(): void
    {
        $this->verdict = 'ERROR';

        $this->artisan('platform:rehearse', ['--admin' => 'ops@example.test', '--keep' => true])
            ->expectsOutputToContain('FAIL  Standard invoice with supply date, cleared')
            ->assertFailed();
    }

    public function test_refuses_outside_the_sandbox(): void
    {
        $organization = app(RehearsalOrganization::class)->prepare((string) $this->admin->id);
        app(EnvironmentSwitch::class)->set($organization, 'simulation', (string) $this->admin->id);
        Http::fake();

        $this->artisan('platform:rehearse', ['--admin' => 'ops@example.test'])
            ->expectsOutputToContain('runs only against the sandbox')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_leaves_real_organizations_alone(): void
    {
        $real = Organization::create([
            'name' => 'Real Trading', 'country' => 'SA', 'status' => 'active',
            'vat_number' => RehearsalOrganization::VAT_NUMBER, 'cr_number' => '1010101010',
        ]);

        Artisan::call('platform:rehearse', ['--admin' => 'ops@example.test']);

        $this->assertNotSame($real->id, app(RehearsalOrganization::class)->find()->id);
        $this->assertSame(0, Invoice::where('org_id', $real->id)->count());
        $this->assertSame(Organization::STATUS_ACTIVE, $real->fresh()->status);
    }

    public function test_requires_a_platform_admin(): void
    {
        $this->artisan('platform:rehearse', ['--admin' => 'nobody@example.test'])->assertFailed();

        $this->assertNull(app(RehearsalOrganization::class)->find());
    }

    public function test_qr_check_catches_an_altered_name(): void
    {
        $organization = app(RehearsalOrganization::class)->prepare((string) $this->admin->id);
        $tlv = app(TlvEncoder::class);
        $invoice = new Invoice(['invoice_number' => 'INV-1', 'type' => 'simplified']);

        $invoice->qr_code = $tlv->encode([1 => 'شركه التجربه التجاريه', 2 => RehearsalOrganization::VAT_NUMBER, 3 => 't', 4 => '1', 5 => '1', 6 => 'h', 7 => 's', 8 => 'k', 9 => 'c']);
        $this->assertStringContainsString('not the registered name', (string) app(Rehearsal::class)->qrProblem($organization, $invoice));

        $invoice->qr_code = $tlv->encode([1 => RehearsalOrganization::NAME_AR, 2 => RehearsalOrganization::VAT_NUMBER, 3 => 't', 4 => '1', 5 => '1', 6 => 'h', 7 => 's', 8 => 'k', 9 => 'c']);
        $this->assertNull(app(Rehearsal::class)->qrProblem($organization, $invoice));
    }

    public function test_audit_check_reads_onboarding_entries(): void
    {
        $organization = app(RehearsalOrganization::class)->prepare((string) $this->admin->id);
        $since = now()->subMinute();

        $this->assertFalse(app(Rehearsal::class)->auditTrail($organization, $since)->passed);

        foreach (['zatca.onboarding.ccsid', 'zatca.onboarding.compliance', 'zatca.onboarding.pcsid'] as $action) {
            AuditLog::create(['org_id' => $organization->id, 'action' => $action, 'entity_type' => 'Organization', 'entity_id' => $organization->id]);
        }

        $this->assertTrue(app(Rehearsal::class)->auditTrail($organization, $since)->passed);
    }

    /**
     * The sandbox, as far as the platform can tell: a certificate for the
     * key in each request, signed by an authority of its own.
     */
    private function answer(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->method() === 'HEAD' => Http::response('', 200),
            str_ends_with($path, '/compliance') => Http::response([
                'requestID' => '1234567890',
                'binarySecurityToken' => base64_encode($this->issued = $this->certify(base64_decode((string) $request['csr']))),
                'secret' => 'compliance-secret',
            ]),
            str_ends_with($path, '/compliance/invoices') => Http::response(['validationResults' => ['status' => 'PASS'], 'reportingStatus' => 'REPORTED']),
            str_ends_with($path, '/production/csids') => Http::response([
                'requestID' => '1234567891',
                'binarySecurityToken' => base64_encode($this->issued),
                'secret' => 'production-secret',
            ]),
            str_ends_with($path, '/invoices/clearance/single') => $this->verdict === 'PASS'
                ? Http::response(['validationResults' => ['status' => 'PASS'], 'clearanceStatus' => 'CLEARED', 'clearedInvoice' => $request['invoice']])
                : Http::response(['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-08', 'message' => 'Refused for the test']]], 'clearanceStatus' => 'NOT_CLEARED'], 400),
            str_ends_with($path, '/invoices/reporting/single') => Http::response(['validationResults' => ['status' => 'PASS'], 'reportingStatus' => 'REPORTED']),
            default => Http::response(['unexpected' => $path], 404),
        };
    }

    /** The certificate's base64 DER, as ZATCA's token carries it. */
    private function certify(string $csr): string
    {
        $config = ['config' => $this->opensslConfig(), 'digest_alg' => 'sha256'];
        $certificate = openssl_csr_sign($csr, $this->authority['certificate'], $this->authority['key'], 365, $config, random_int(1 << 32, PHP_INT_MAX));
        $this->assertNotFalse($certificate, 'could not issue: '.openssl_error_string());
        openssl_x509_export($certificate, $pem);

        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    /**
     * @return array{key: \OpenSSLAsymmetricKey, certificate: \OpenSSLCertificate}
     */
    private function authority(): array
    {
        $config = ['config' => $this->opensslConfig(), 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($config + ['curve_name' => 'secp256k1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $csr = openssl_csr_new(['commonName' => 'Test Issuing CA'], $key, $config);
        $certificate = openssl_csr_sign($csr, null, $key, 365, $config, 1);

        return ['key' => $key, 'certificate' => $certificate];
    }
}
