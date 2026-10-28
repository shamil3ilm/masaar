<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which ZATCA environments the deployment's organizations file with.
 *
 * Each organization has an environment of its own, defaulting to the
 * deployment's. Monitoring that read only the default could not see one
 * organization moved to production on a server that defaults to the sandbox,
 * and that organization's invoices carry legal weight.
 */
class EnvironmentsInUseTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_alone_when_none_chosen(): void
    {
        config(['fatoora.environment' => 'simulation']);

        $this->assertSame(['simulation'], FatooraEnvironment::inUse());
    }

    public function test_chosen_environments_join_the_default(): void
    {
        config(['fatoora.environment' => 'sandbox']);
        $this->organization(FatooraEnvironment::PRODUCTION);

        $this->assertSame(['sandbox', 'production'], FatooraEnvironment::inUse());
    }

    public function test_health_lists_environments_in_use(): void
    {
        config(['fatoora.environment' => 'sandbox']);
        $this->organization(FatooraEnvironment::PRODUCTION);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('zatca_environment', 'sandbox')
            ->assertJsonPath('zatca_environments', ['sandbox', 'production']);
    }

    /** Liveness answers while PHP does, database or not. */
    public function test_health_survives_no_database(): void
    {
        $default = config('database.default');
        config([
            'database.connections.unreachable' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/no-such-dir-'.uniqid().'/x.sqlite'],
            'database.default' => 'unreachable',
        ]);

        try {
            $response = $this->getJson('/api/health');
        } finally {
            // Back before the test's own transaction is rolled back.
            config(['database.default' => $default]);
            DB::purge('unreachable');
        }

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('zatca_environments', null);
    }

    private function organization(string $environment): void
    {
        Organization::create(['name' => 'Live Co', 'country' => 'SA', 'vat_number' => '300000000000013',
            'compliance_profile' => [FatooraEnvironment::PROFILE_KEY => $environment]]);
    }
}
