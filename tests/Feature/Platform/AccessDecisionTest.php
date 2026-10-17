<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Decision;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Access\Level;
use App\Domains\Platform\Services\KillSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Access asks platform, country, plan, organization and role in that order,
 * and the first that says no decides. Each level is shown winning over the
 * ones below it, and every reason it can give is given once.
 *
 * Countries come from the registry, never by name: the answers are the same
 * shape for every country, and what a country supports is its module's.
 */
class AccessDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Access $access;

    private KillSwitch $switches;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = app(Access::class);
        $this->switches = app(KillSwitch::class);

        foreach ($this->registry()->registered() as $code) {
            app(JurisdictionAvailability::class)->set($code, true, 'ops@masaar.test');
        }
    }

    public function test_platform_wins_over_country(): void
    {
        $organization = $this->organization();
        app(JurisdictionAvailability::class)->set($this->code($organization), false, 'ops@masaar.test');
        $this->switches->enable(KillSwitch::SWITCH_ISSUANCE, reason: 'Signing defect');

        $decision = $this->decide($organization, Feature::IssueInvoices);

        $this->assertRefused($decision, Level::Platform, 'kill_switch');
        $this->assertStringContainsString('Signing defect', $decision->reason);
        $this->assertSame(KillSwitch::SWITCH_ISSUANCE, $decision->detail['kill_switch']);
    }

    public function test_country_wins_over_plan(): void
    {
        $organization = $this->organization($this->supporting(Feature::IssueStandard));
        $this->licence($organization, 'starter');
        app(JurisdictionAvailability::class)->set($this->code($organization), false, 'ops@masaar.test');

        $this->assertRefused($this->decide($organization, Feature::IssueStandard), Level::Country, 'country_off');
    }

    public function test_plan_wins_over_organization(): void
    {
        $organization = $this->organization($this->supporting(Feature::IssueStandard), Organization::STATUS_SUSPENDED);
        $this->licence($organization, 'starter');

        $decision = $this->decide($organization, Feature::IssueStandard);

        $this->assertRefused($decision, Level::Plan, 'plan_excludes');
        $this->assertSame('standard_invoices', $decision->detail['licence_feature']);
        $this->assertStringContainsString('Starter', $decision->reason);
    }

    public function test_organization_wins_over_role(): void
    {
        $organization = $this->organization(status: Organization::STATUS_SUSPENDED);

        $this->assertRefused($this->decide($organization, Feature::IssueInvoices, $this->member($organization, 'viewer')), Level::Organization, 'status');
    }

    public function test_role_decides_last(): void
    {
        $organization = $this->organization();

        $this->assertRefused($this->decide($organization, Feature::IssueInvoices, $this->member($organization, 'viewer')), Level::Role, 'role');
        $this->assertTrue($this->decide($organization, Feature::IssueInvoices, $this->member($organization, 'member'))->allowed);
        $this->assertTrue($this->decide($organization, Feature::IssueInvoices)->allowed, 'no person, no role level');
    }

    /**
     * No module, the country switched off - only for what starts new work -
     * and a feature the country does not have at all.
     */
    public function test_country_gives_each_reason(): void
    {
        $this->assertRefused($this->decide($this->organization('ZZ'), Feature::IssueInvoices), Level::Country, 'no_module');

        $organization = $this->organization();
        app(JurisdictionAvailability::class)->set($this->code($organization), false, 'ops@masaar.test');
        $this->assertRefused($this->decide($organization, Feature::IssueInvoices), Level::Country, 'country_off');
        $this->assertRefused($this->decide($organization, Feature::FileWithAuthority), Level::Country, 'country_off');
        $this->assertTrue($this->decide($organization, Feature::Webhooks)->allowed, 'what starts no new work is not stopped by the country switch');

        [$code, $feature] = $this->unsupported();
        $decision = $this->decide($this->organization($code), $feature);
        $this->assertRefused($decision, Level::Country, 'not_in_country');
        $this->assertSame($code, $decision->detail['country']);
    }

    public function test_plan_gives_each_reason(): void
    {
        $unlicensed = $this->organization();
        $this->assertRefused($this->decide($unlicensed, Feature::ApiAccess), Level::Plan, 'no_licence');
        $this->assertTrue($this->decide($unlicensed, Feature::IssueInvoices)->allowed, 'no plan required by this deployment');

        config(['licensing.filing_requires_licence' => true]);
        $this->assertRefused($this->decide($unlicensed, Feature::IssueInvoices), Level::Plan, 'no_plan');

        // In review: given its plan when approved, so the review is the reason.
        $pending = $this->organization(status: Organization::STATUS_PENDING_REVIEW);
        $this->assertRefused($this->decide($pending, Feature::IssueInvoices), Level::Organization, 'status');

        $scoped = $this->organization();
        $this->licence($scoped, 'enterprise', scopes: ['invoice.read']);
        $this->assertRefused($this->decide($scoped, Feature::ErpPipeline), Level::Plan, 'scope_missing');
        $this->assertTrue($this->decide($scoped, Feature::ApiAccess)->allowed);
    }

    public function test_organization_gives_each_reason(): void
    {
        foreach ([Organization::STATUS_PENDING_REVIEW => 'being reviewed', Organization::STATUS_SUSPENDED => 'suspended', Organization::STATUS_REJECTED => 'not approved'] as $status => $words) {
            $decision = $this->decide($this->organization(status: $status), Feature::IssueInvoices);

            $this->assertRefused($decision, Level::Organization, 'status');
            $this->assertStringContainsString($words, $decision->reason);
            $this->assertTrue($this->decide($this->organization(status: $status), Feature::Webhooks)->allowed, 'review status gates new work only');
        }

        $paused = $this->organization();
        $this->switches->enable(KillSwitch::SWITCH_ISSUANCE, KillSwitch::SCOPE_TENANT, (string) $paused->id, 'Chain repair');
        $decision = $this->decide($paused, Feature::IssueInvoices);
        $this->assertRefused($decision, Level::Organization, 'org_kill_switch');
        $this->assertStringContainsString('Chain repair', $decision->reason);
        $this->assertTrue($this->decide($this->organization(), Feature::IssueInvoices)->allowed, 'another company is not paused');

        $switched = $this->organization();
        app(FeatureOverrides::class)->set((string) $switched->id, Feature::IssueInvoices, 'off', 'Disputed invoices', 'ops@masaar.test');
        $decision = $this->decide($switched, Feature::IssueInvoices);
        $this->assertRefused($decision, Level::Organization, 'override_off');
        $this->assertStringContainsString('Disputed invoices', $decision->reason);
    }

    public function test_role_gives_each_reason(): void
    {
        $organization = $this->organization();

        $this->assertRefused($this->decide($organization, Feature::IssueInvoices, User::factory()->create()), Level::Role, 'not_member');

        $viewer = $this->decide($organization, Feature::IssueInvoices, $this->member($organization, 'viewer'));
        $this->assertSame('viewer', $viewer->detail['role']);
        $this->assertStringContainsString('read-only', $viewer->reason);

        $member = $this->decide($organization, Feature::Webhooks, $this->member($organization, 'member'));
        $this->assertRefused($member, Level::Role, 'role');
        $this->assertStringContainsString('administrator', $member->reason);
        $this->assertTrue($this->decide($organization, Feature::Webhooks, $this->member($organization, 'admin'))->allowed);

        $receiving = $this->organization($this->supporting(Feature::ReceiveInvoices));
        $this->assertTrue($this->decide($receiving, Feature::ReceiveInvoices, $this->member($receiving, 'viewer'))->allowed, 'a viewer reads what was received');
    }

    /**
     * Granted beyond the plan lifts the plan's list and nothing else: a
     * kill switch still refuses.
     */
    public function test_grant_lifts_only_the_plan(): void
    {
        $organization = $this->organization($this->supporting(Feature::IssueStandard));
        $this->licence($organization, 'starter');
        app(FeatureOverrides::class)->set((string) $organization->id, Feature::IssueStandard, 'on', 'Trial of B2B', 'ops@masaar.test');

        $decision = $this->decide($organization, Feature::IssueStandard);
        $this->assertTrue($decision->allowed);
        $this->assertSame(Level::Plan, $decision->source);
        $this->assertSame('override_on', $decision->code);

        $this->switches->enable(KillSwitch::SWITCH_ISSUANCE, reason: 'Incident');
        $this->assertRefused($this->decide($organization, Feature::IssueStandard), Level::Platform, 'kill_switch');
    }

    public function test_untouched_feature_is_simply_allowed(): void
    {
        $decision = $this->decide($this->organization(), Feature::Webhooks);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->source);
        $this->assertSame('allowed', $decision->code);
    }

    /**
     * A switch only some countries obey - the module says which - refuses
     * there and nowhere else.
     */
    public function test_country_switches_stop_their_country(): void
    {
        foreach ($this->registry()->registered() as $code) {
            foreach ($this->module($code)->features()->killSwitchesFor(Feature::IssueInvoices) as $switch) {
                $this->switches->enable($switch, reason: 'Module switch');

                foreach ($this->registry()->registered() as $other) {
                    $obeys = in_array($switch, $this->module($other)->features()->killSwitchesFor(Feature::IssueInvoices), true);
                    $this->assertSame(! $obeys, $this->decide($this->organization($other), Feature::IssueInvoices)->allowed, "{$switch} in {$other}");
                }

                $this->switches->disable($switch);
            }
        }
    }

    private function decide(Organization $organization, Feature $feature, ?User $user = null): Decision
    {
        return $this->access->decide($organization, $user, $feature);
    }

    private function assertRefused(Decision $decision, Level $level, string $code): void
    {
        $this->assertFalse($decision->allowed, "expected {$code}, got {$decision->code}: {$decision->reason}");
        $this->assertSame($level, $decision->source, "decided by {$decision->source?->value}: {$decision->reason}");
        $this->assertSame($code, $decision->code, $decision->reason);
        $this->assertNotSame('', $decision->reason);
    }

    private function organization(?string $code = null, string $status = Organization::STATUS_ACTIVE): Organization
    {
        return Organization::create([
            'name' => 'Acme '.uniqid(),
            'country' => $code ?? $this->registry()->defaultCode(),
            'status' => $status,
        ]);
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    /**
     * @param  list<string>|null  $scopes
     */
    private function licence(Organization $organization, string $tier, ?array $scopes = null): License
    {
        $key = uniqid();

        return License::create([
            'org_id' => $organization->id,
            'api_key' => 'cp_test_'.$key,
            'api_secret_hash' => License::hashSecret('secret-'.$key),
            'organization_name' => $organization->name,
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => $tier,
            'status' => 'active',
            'scopes' => $scopes ?? ['invoice.submit', 'invoice.read', 'webhook.manage', 'compliance.status'],
        ]);
    }

    private function supporting(Feature $feature): string
    {
        foreach ($this->registry()->registered() as $code) {
            if ($this->module($code)->features()->supportsFeature($feature)) {
                return $code;
            }
        }

        $this->fail("No registered country supports {$feature->value}.");
    }

    /**
     * @return array{0: string, 1: Feature}
     */
    private function unsupported(): array
    {
        foreach ($this->registry()->registered() as $code) {
            foreach (Feature::cases() as $feature) {
                if (! $this->module($code)->features()->supportsFeature($feature)) {
                    return [$code, $feature];
                }
            }
        }

        $this->fail('Every registered country supports every feature.');
    }

    private function code(Organization $organization): string
    {
        return (string) $organization->country;
    }

    private function module(string $code): Jurisdiction
    {
        return $this->registry()->get($code);
    }

    private function registry(): JurisdictionRegistry
    {
        return app(JurisdictionRegistry::class);
    }
}
