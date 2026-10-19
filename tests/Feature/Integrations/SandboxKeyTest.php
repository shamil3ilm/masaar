<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Compliance\Networks\Peppol\NetworkServiceProvider;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuingLifecycle;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\SandboxGuard;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sandbox key never files to production.
 *
 * Where the documents go is the deployment's: the authority's environment
 * (ZATCA_ENVIRONMENT) and the network's gateway (PEPPOL_GATEWAY=simulator).
 * A sandbox key works where those are a sandbox or the simulator, and is
 * refused before anything is issued where the country files live - at the
 * route, and again where every country issues.
 */
class SandboxKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_key_refused_on_live_filing(): void
    {
        config(['fatoora.environment' => 'production']);
        [$key, $secret] = $this->key($this->saudi(), LicenseEnvironment::Sandbox);

        foreach (['/api/v1/pipeline/submit', '/api/v1/pipeline/batch'] as $uri) {
            $this->withHeaders(['X-API-Key' => $key->api_key, 'X-API-Secret' => $secret])
                ->postJson($uri, [])
                ->assertForbidden()
                ->assertJsonPath('error.code', 'AUTH_ENV_MISMATCH');
        }
    }

    public function test_sandbox_key_reaches_sandbox_filing(): void
    {
        config(['fatoora.environment' => 'sandbox']);
        [$key, $secret] = $this->key($this->saudi(), LicenseEnvironment::Sandbox);

        $response = $this->withHeaders(['X-API-Key' => $key->api_key, 'X-API-Secret' => $secret])->postJson('/api/v1/pipeline/submit', []);

        // Past the guard: refused for the empty invoice, not the key.
        $this->assertNotSame('AUTH_ENV_MISMATCH', $response->json('error.code'));
        $response->assertUnprocessable();
    }

    public function test_production_key_files_live(): void
    {
        config(['fatoora.environment' => 'production']);
        [$key, $secret] = $this->key($this->saudi(), LicenseEnvironment::Production);

        $response = $this->withHeaders(['X-API-Key' => $key->api_key, 'X-API-Secret' => $secret])->postJson('/api/v1/pipeline/submit', []);

        $this->assertNotSame('AUTH_ENV_MISMATCH', $response->json('error.code'));
    }

    public function test_each_country_says_when_live(): void
    {
        $saudi = $this->saudi();
        $uae = Organization::create(['name' => 'Gulf LLC', 'country' => 'AE', 'status' => 'active']);
        [$key] = $this->key($saudi, LicenseEnvironment::Sandbox);
        request()->attributes->set(ApiKey::ATTRIBUTE, $key);
        $guard = app(SandboxGuard::class);

        foreach (['sandbox' => false, 'simulation' => false, 'production' => true] as $environment => $live) {
            config(['fatoora.environment' => $environment]);
            $this->assertSame($live, $guard->refuses($saudi), $environment);
        }

        config(['peppol.gateway' => null]);
        $this->assertTrue($guard->refuses($uae), 'a provider, or none, is the live network');
        config(['peppol.gateway' => NetworkServiceProvider::SIMULATOR]);
        $this->assertFalse($guard->refuses($uae));

        $key->environment = LicenseEnvironment::Production;
        config(['peppol.gateway' => null]);
        $this->assertFalse($guard->refuses($uae), 'a production key is never held back here');
    }

    public function test_issuing_itself_refuses_sandbox_keys(): void
    {
        config(['fatoora.environment' => 'production']);
        $saudi = $this->saudi();
        [$key] = $this->key($saudi, LicenseEnvironment::Sandbox);
        request()->attributes->set(ApiKey::ATTRIBUTE, $key);

        $this->expectException(LicenseException::class);

        app(IssuingLifecycle::class)->issue(new Invoice, $saudi, app(JurisdictionResolver::class)->forRules($saudi)->issuingStep());
    }

    private function saudi(): Organization
    {
        return Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
    }

    /**
     * @return array{ApiKey, string}
     */
    private function key(Organization $organization, LicenseEnvironment $environment): array
    {
        License::createWithCredentials([
            'org_id' => $organization->id, 'organization_name' => $organization->name, 'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Enterprise, 'scopes' => ['invoice.submit', 'compliance.status'],
        ]);

        $secret = License::generateApiSecret();
        $key = new ApiKey(['name' => 'ERP', 'environment' => $environment, 'scopes' => ['invoice.submit', 'compliance.status']]);
        $key->forceFill([
            'org_id' => $organization->id,
            'api_key' => License::generateApiKey($environment),
            'secret_hash' => License::hashSecret($secret),
            'status' => ApiKey::ACTIVE,
        ])->save();

        return [$key, $secret];
    }
}
