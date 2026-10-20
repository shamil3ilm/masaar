<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Networks\Peppol\NetworkServiceProvider;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseAuditLog;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A partner licence has the environment of the deployment it files on.
 *
 * Licences issued from Admin › Licences were never given one, so every one
 * was a sandbox licence (cp_test_), and the authority's own check refused it
 * the moment the deployment filed with the live authority. The console now
 * asks, starting from the deployment's live environment, and the licences
 * already issued on a deployment that files live are moved to production,
 * each with an audit entry.
 */
class LicenceEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/1370_licence_environment_follows_filing.php';

    public function test_form_starts_from_the_live_environment(): void
    {
        $this->filingLive(true);

        $this->actingAs($this->admin())->get(route('admin.licences.create'))->assertOk()
            ->assertSee('name="environment"', false)
            ->assertSee('<option value="production" selected', false);

        $this->filingLive(false);

        $this->actingAs($this->admin())->get(route('admin.licences.create'))->assertOk()
            ->assertSee('<option value="sandbox" selected', false);
    }

    public function test_issued_licence_takes_the_chosen_environment(): void
    {
        $this->filingLive(true);

        $this->actingAs($this->admin())->post('/admin/licences', $this->form(['environment' => 'production']));
        $live = License::query()->where('organization_name', 'Northwind')->firstOrFail();

        $this->assertSame(LicenseEnvironment::Production, $live->environment);
        $this->assertStringStartsWith('cp_live_', (string) $live->api_key);

        $this->actingAs($this->admin())->post('/admin/licences', $this->form(['organization_name' => 'Test partner', 'environment' => 'sandbox']));

        $this->assertSame(LicenseEnvironment::Sandbox, License::query()->where('organization_name', 'Test partner')->firstOrFail()->environment);
    }

    public function test_an_unknown_environment_is_refused(): void
    {
        $this->actingAs($this->admin())->post('/admin/licences', $this->form(['environment' => 'staging']))
            ->assertSessionHasErrors('environment');

        $this->assertDatabaseMissing('licenses', ['organization_name' => 'Northwind']);
    }

    /** A caller that does not say - the admin API, an approval - gets the organization's country's. */
    public function test_omitted_environment_follows_the_organization(): void
    {
        $saudi = $this->organization('SA');
        $this->filingLive(true);

        $this->actingAs($this->admin())->post('/admin/licences', $this->form(['org_id' => $saudi->id]));

        $this->assertSame(LicenseEnvironment::Production, License::query()->where('org_id', $saudi->id)->firstOrFail()->environment);
    }

    public function test_issuing_audits_the_environment(): void
    {
        $this->filingLive(true);

        $this->actingAs($this->admin())->post('/admin/licences', $this->form(['environment' => 'production']));
        $licence = License::query()->where('organization_name', 'Northwind')->firstOrFail();

        $entry = LicenseAuditLog::query()->where('license_id', $licence->id)->where('event', 'created')->firstOrFail();
        $this->assertSame('production', $entry->new_values['environment'] ?? null);
    }

    public function test_migration_moves_licences_on_live_deployments(): void
    {
        $saudi = $this->sandboxLicence($this->organization('SA'));
        $uae = $this->sandboxLicence($this->organization('AE'));
        $unattached = $this->sandboxLicence(null);
        config(['fatoora.environment' => 'production', 'peppol.gateway' => NetworkServiceProvider::SIMULATOR]);

        $this->migration()->up();

        $this->assertSame(LicenseEnvironment::Production, $saudi->refresh()->environment);
        $this->assertSame(LicenseEnvironment::Sandbox, $uae->refresh()->environment, 'the UAE files to the simulator here');
        $this->assertSame(LicenseEnvironment::Production, $unattached->refresh()->environment, 'the deployment files live');

        $entry = LicenseAuditLog::query()->where('license_id', $saudi->id)->where('event', 'environment_changed')->firstOrFail();
        $this->assertSame('sandbox', $entry->old_values['environment'] ?? null);
        $this->assertSame('production', $entry->new_values['environment'] ?? null);
        $this->assertSame('system', $entry->actor_type);
        $this->assertNotEmpty($entry->reason);
        $this->assertSame(0, LicenseAuditLog::query()->where('license_id', $uae->id)->count());
    }

    public function test_migration_leaves_sandbox_deployments_alone(): void
    {
        $saudi = $this->sandboxLicence($this->organization('SA'));
        $uae = $this->sandboxLicence($this->organization('AE'));
        $this->filingLive(false);

        $this->migration()->up();

        $this->assertSame(LicenseEnvironment::Sandbox, $saudi->refresh()->environment);
        $this->assertSame(LicenseEnvironment::Sandbox, $uae->refresh()->environment);
        $this->assertSame(0, LicenseAuditLog::query()->where('event', 'environment_changed')->count());
    }

    public function test_migration_down_restores_the_sandbox(): void
    {
        $saudi = $this->sandboxLicence($this->organization('SA'));
        $chosen = $this->sandboxLicence($this->organization('SA'), LicenseEnvironment::Production);
        $this->filingLive(true);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertSame(LicenseEnvironment::Sandbox, $saudi->refresh()->environment);
        $this->assertSame(LicenseEnvironment::Production, $chosen->refresh()->environment, 'only what the migration moved');
        $this->assertSame(2, LicenseAuditLog::query()->where('license_id', $saudi->id)->where('event', 'environment_changed')->count());
    }

    private function filingLive(bool $live): void
    {
        config([
            'fatoora.environment' => $live ? 'production' : 'sandbox',
            'peppol.gateway' => $live ? null : NetworkServiceProvider::SIMULATOR,
        ]);
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    private function organization(string $country): Organization
    {
        return Organization::create(['name' => 'Acme '.$country, 'country' => $country, 'status' => 'active']);
    }

    private function sandboxLicence(?Organization $organization, LicenseEnvironment $environment = LicenseEnvironment::Sandbox): License
    {
        return License::createWithCredentials([
            'org_id' => $organization?->id, 'organization_name' => $organization->name ?? 'Unattached', 'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Professional, 'environment' => $environment,
        ])['license'];
    }

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return [
            'organization_name' => 'Northwind',
            'contact_email' => 'ops@northwind.test',
            'tier' => LicenseTier::Professional->value,
            ...$overrides,
        ];
    }
}
