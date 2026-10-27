<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\RevocationAuthority;
use Tests\TestCase;

/**
 * A production instance with Saudi Arabia on is not ready to take traffic
 * until every certificate it stamps with can be checked for revocation.
 *
 * "Before using the certificate for stamping, EGS/ZATCA e-invoicing platform
 * shall check certificate's validity" against the CA's CRLs (Security
 * Features Implementation Standard v1.2, section 2.2.1, requirement 14). The
 * check finds a list in the certificate's CRL distribution points, else in
 * ZATCA_CRL_URLS, and trusts an http list only once its signature verifies
 * against the CA certificate in ZATCA_CA_CHAIN_PATH. Without either the
 * check fails open, so production readiness fails instead.
 */
class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;
    use RevocationAuthority;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config([
            'fatoora.signing.disk' => 'creds',
            'fatoora.enabled' => true,
            'fatoora.environment' => 'production',
            'fatoora.certificate.crl_urls' => [],
        ]);
        $this->authority();
        $this->app['env'] = 'production';

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003']);
    }

    public function test_ready_with_chain_and_named_lists(): void
    {
        $this->store($this->issuedDevice(5001));

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.countries', 'ok');
    }

    public function test_a_missing_chain_is_not_ready(): void
    {
        config(['fatoora.certificate.chain_path' => null]);

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.countries', 'failing');
    }

    public function test_an_unreadable_chain_is_not_ready(): void
    {
        config(['fatoora.certificate.chain_path' => sys_get_temp_dir().'/platform-no-chain-'.uniqid().'.pem']);

        $this->getJson('/api/health/ready')->assertStatus(503);
    }

    /**
     * A certificate that names no list, and none configured: nothing to
     * check it against before it stamps.
     */
    public function test_certificate_without_any_list_fails(): void
    {
        $this->store($this->issuedDevice(5002, namesList: false));

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.countries', 'failing');
    }

    public function test_configured_lists_cover_unnamed_certificates(): void
    {
        config(['fatoora.certificate.crl_urls' => ['https://crl.authority.test/issuing.crl']]);
        $this->store($this->issuedDevice(5003, namesList: false));

        $this->getJson('/api/health/ready')->assertOk();
    }

    public function test_switched_off_country_is_not_asked(): void
    {
        config(['fatoora.enabled' => false, 'fatoora.certificate.chain_path' => null]);

        $this->getJson('/api/health/ready')->assertOk();
    }

    /**
     * A deployment rehearsing on ZATCA's simulation gateway stamps nothing
     * with legal weight, so a missing chain does not keep it from traffic.
     */
    public function test_simulation_gateway_is_not_asked(): void
    {
        config(['fatoora.environment' => 'simulation', 'fatoora.certificate.chain_path' => null]);

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.countries', 'ok');
    }

    /**
     * The environment is each organization's (FatooraEnvironment), not only
     * the deployment's default: one production organization on a deployment
     * that defaults to the sandbox stamps with legal weight all the same.
     */
    public function test_production_organization_is_asked(): void
    {
        config(['fatoora.environment' => 'sandbox', 'fatoora.certificate.chain_path' => null]);
        $this->acme->update(['compliance_profile' => [FatooraEnvironment::PROFILE_KEY => FatooraEnvironment::PRODUCTION]]);

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.countries', 'failing');
    }

    /** And only its certificates are counted, not a sandbox one's. */
    public function test_sandbox_certificates_are_not_counted(): void
    {
        config(['fatoora.environment' => 'sandbox']);
        $this->store($this->issuedDevice(5004, namesList: false));
        Organization::create(['name' => 'Live Co', 'country' => 'SA', 'vat_number' => '300000000000013',
            'compliance_profile' => [FatooraEnvironment::PROFILE_KEY => FatooraEnvironment::PRODUCTION]]);

        $this->getJson('/api/health/ready')->assertOk();
    }

    public function test_outside_production_is_not_asked(): void
    {
        $this->app['env'] = 'testing';
        config(['fatoora.certificate.chain_path' => null]);

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready', 'checks' => ['database' => 'ok', 'cache' => 'ok']]);
    }

    /**
     * @param  array{privateKey: string, certificate: string}  $device
     */
    private function store(array $device): void
    {
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $device['privateKey'], 'pcsid' => $device['certificate'], 'secret' => 'device-secret',
        ]);
    }
}
