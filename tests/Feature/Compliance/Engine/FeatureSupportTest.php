<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Engine;

use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\Level;
use App\Domains\Platform\Services\KillSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every country answers the features port for every feature, and Access
 * refuses a feature as not part of a country exactly where that country's
 * module says so. Read from the registry, so a new country is covered the day
 * it registers.
 */
class FeatureSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_follows_each_module(): void
    {
        foreach ($this->registry()->registered() as $code) {
            app(JurisdictionAvailability::class)->set($code, true, 'ops@masaar.test');
            $organization = Organization::create(['name' => 'Acme '.$code, 'country' => $code, 'status' => Organization::STATUS_ACTIVE]);
            $support = $this->registry()->get($code)->features();

            foreach (Feature::cases() as $feature) {
                $decision = app(Access::class)->decide($organization, null, $feature);
                $notHere = $decision->source === Level::Country && $decision->code === 'not_in_country';

                $this->assertSame(! $support->supportsFeature($feature), $notHere, "{$code} {$feature->value}: {$decision->reason}");
            }
        }
    }

    /** Only switches that exist, and never the one that forces offline rather than stopping. */
    public function test_module_switches_are_real_stops(): void
    {
        foreach ($this->registry()->registered() as $code) {
            foreach (Feature::cases() as $feature) {
                foreach ($this->registry()->get($code)->features()->killSwitchesFor($feature) as $switch) {
                    $this->assertContains($switch, KillSwitch::SWITCHES, "{$code} {$feature->value}");
                    $this->assertNotSame(KillSwitch::SWITCH_OFFLINE_MODE, $switch, "{$code} {$feature->value}");
                }
            }
        }
    }

    /**
     * Every country issues and files - that is what a module is for - and
     * the catalogue is not the same everywhere, which is why each module is
     * asked.
     */
    public function test_every_country_issues_and_files(): void
    {
        $codes = $this->registry()->registered();
        $profiles = [];

        foreach ($codes as $code) {
            $support = $this->registry()->get($code)->features();

            $this->assertTrue($support->supportsFeature(Feature::IssueInvoices), $code);
            $this->assertTrue($support->supportsFeature(Feature::FileWithAuthority), $code);

            $profiles[$code] = array_map(fn (Feature $feature): bool => $support->supportsFeature($feature), Feature::cases());
        }

        if (count($codes) > 1) {
            $this->assertGreaterThan(1, count(array_unique(array_map('serialize', $profiles))), 'every country supports exactly the same features');
        }
    }

    private function registry(): JurisdictionRegistry
    {
        return app(JurisdictionRegistry::class);
    }
}
