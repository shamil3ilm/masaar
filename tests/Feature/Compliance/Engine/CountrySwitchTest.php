<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Engine;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * One switch per country, the same for every country: the module's
 * configured default until a platform admin throws it, and then the switch.
 */
class CountrySwitchTest extends TestCase
{
    use RefreshDatabase;

    private JurisdictionAvailability $switches;

    protected function setUp(): void
    {
        parent::setUp();

        $this->switches = app(JurisdictionAvailability::class);
    }

    /**
     * With no switch thrown, each country answers its own default:
     * ZATCA_ENABLED and PEPPOL_AE_ENABLED.
     */
    public function test_defaults_follow_each_country_configuration(): void
    {
        config(['fatoora.enabled' => true, 'peppol.countries.AE.enabled' => false]);
        $this->assertSame(['SA'], $this->registry()->enabled());

        config(['fatoora.enabled' => false, 'peppol.countries.AE.enabled' => true]);
        $this->assertSame(['AE'], $this->registry()->enabled());

        $this->assertNull($this->switches->switched('SA'));
        $this->assertNull($this->switches->switched('AE'));
    }

    /**
     * The switch wins over the default in both directions, for both
     * countries, and records who threw it.
     */
    public function test_admin_switch_overrides_the_default(): void
    {
        config(['fatoora.enabled' => true, 'peppol.countries.AE.enabled' => false]);

        $this->switches->set('SA', false, 'ops@masaar.test');
        $this->switches->set('AE', true, 'ops@masaar.test');

        $this->assertSame(['AE'], $this->registry()->enabled());
        $this->assertSame('ops@masaar.test', $this->switches->switched('SA')['by']);
        $this->assertFalse($this->switches->switched('SA')['enabled']);

        $this->switches->set('SA', true, 'ops@masaar.test');

        $this->assertSame(['AE', 'SA'], $this->registry()->enabled());
    }

    /** The company country list follows, so a switched-off country cannot be chosen. */
    public function test_company_countries_follow_the_switch(): void
    {
        $this->switches->set('SA', false, 'ops@masaar.test');

        $this->assertSame(['AE'], CompanyProfileRules::countries());

        $this->switches->set('AE', false, 'ops@masaar.test');

        $this->assertSame([], CompanyProfileRules::countries());
    }

    /**
     * Saudi Arabia switched off refuses an ERP's filing as the UAE's does:
     * 422 JURISDICTION_UNAVAILABLE, before anything is drafted, on the
     * pipeline and on the country's own routes.
     */
    public function test_switched_off_saudi_refuses_the_api(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->switches->set('SA', false, 'ops@masaar.test');

        $this->withLicence($organization)->postJson('/api/v1/pipeline/submit', [
            'org_id' => $organization->id,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JURISDICTION_UNAVAILABLE');

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * Saudi Arabia's own routes that start new work are refused the same
     * way, and its status read stays open for what was already filed.
     */
    public function test_saudi_routes_refuse_new_work(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);
        $this->switches->set('SA', false, 'ops@masaar.test');

        $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user))
            ->postJson('/api/compliance/sa/submit/'.Str::uuid())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JURISDICTION_UNAVAILABLE');

        $status = $this->getJson('/api/compliance/sa/status/'.Str::uuid());
        $this->assertNotSame('JURISDICTION_UNAVAILABLE', $status->json('error.code'));
    }

    private function registry(): JurisdictionRegistry
    {
        return app(JurisdictionRegistry::class);
    }

    private function withLicence(Organization $organization): static
    {
        $issued = License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => $organization->name,
            'contact_email' => 'erp@acme.test',
            'tier' => 'starter',
        ]);

        return $this->withHeaders(['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']]);
    }
}
