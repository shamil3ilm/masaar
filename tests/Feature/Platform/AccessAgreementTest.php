<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Access\Level;
use App\Domains\Platform\Services\KillSwitch;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Where an enforcement point kept its own check, Access gives the same
 * answer.
 *
 * Each country's issuing step still checks the kill switches and filing
 * clearance itself, on every path; the resolver still refuses a country
 * switched off; OrgWriter and IsOrgAdmin still gate routes by role; the
 * partner API's feature check still reads the licence. Each is run here
 * against Access in the same situation, in every registered country.
 */
class AccessAgreementTest extends TestCase
{
    use RefreshDatabase;

    /** What the issuing steps' gates refuse with; anything else is a later rule. */
    private const GATES = [
        ErrorCode::SYS_MAINTENANCE_MODE,
        ErrorCode::AUTH_ORGANIZATION_SUSPENDED,
        ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW,
        ErrorCode::AUTH_LICENSE_REQUIRED,
        ErrorCode::AUTH_FEATURE_SWITCHED_OFF,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->registry()->registered() as $code) {
            app(JurisdictionAvailability::class)->set($code, true, 'ops@masaar.test');
        }
    }

    /**
     * Every situation the issuing step refuses in - or does not - Access
     * refuses issuing in too, in every country.
     */
    public function test_issuing_steps_agree_with_access(): void
    {
        $switches = app(KillSwitch::class);

        foreach ($this->registry()->registered() as $code) {
            $scenarios = [
                'nothing in the way' => fn (Organization $o) => null,
                'issuance stopped' => fn (Organization $o) => $switches->enable(KillSwitch::SWITCH_ISSUANCE, reason: 'Test'),
                'issuance stopped for it' => fn (Organization $o) => $switches->enable(KillSwitch::SWITCH_ISSUANCE, KillSwitch::SCOPE_TENANT, (string) $o->id, 'Test'),
                'suspended' => fn (Organization $o) => $o->forceFill(['status' => Organization::STATUS_SUSPENDED])->save(),
                'in review' => fn (Organization $o) => $o->forceFill(['status' => Organization::STATUS_PENDING_REVIEW])->save(),
                'turned down' => fn (Organization $o) => $o->forceFill(['status' => Organization::STATUS_REJECTED])->save(),
                'no plan' => fn (Organization $o) => config(['licensing.filing_requires_licence' => true]),
                'no plan, in review' => function (Organization $o): void {
                    config(['licensing.filing_requires_licence' => true]);
                    $o->forceFill(['status' => Organization::STATUS_PENDING_REVIEW])->save();
                },
                'switched off by an admin' => fn (Organization $o) => app(FeatureOverrides::class)->set((string) $o->id, Feature::IssueInvoices, 'off', 'Test', null),
            ];

            foreach ($this->registry()->get($code)->features()->killSwitchesFor(Feature::IssueInvoices) as $switch) {
                $scenarios["{$switch} stopped"] = fn (Organization $o) => $switches->enable($switch, reason: 'Test');
            }

            foreach ($scenarios as $name => $arrange) {
                $this->agreeOnIssuing($code, $name, $arrange);
            }
        }
    }

    /** The resolver, behind every issuing caller, and the country level. */
    public function test_resolver_agrees_on_country(): void
    {
        foreach ($this->registry()->registered() as $code) {
            $organization = $this->organization($code);

            foreach ([true, false] as $on) {
                app(JurisdictionAvailability::class)->set($code, $on, 'ops@masaar.test');

                $decision = app(Access::class)->decide($organization, null, Feature::IssueInvoices);

                $this->assertSame(app(JurisdictionResolver::class)->available($organization), $decision->source !== Level::Country, $code);
            }
        }

        $unknown = $this->organization('ZZ');
        $this->assertFalse(app(JurisdictionResolver::class)->available($unknown));
        $this->assertSame('no_module', app(Access::class)->decide($unknown, null, Feature::IssueInvoices)->code);
    }

    /**
     * OrgWriter refuses a viewer's writes, and the role level refuses a
     * viewer what a member may do.
     */
    public function test_org_writer_agrees_on_roles(): void
    {
        $organization = $this->organization($this->registry()->defaultCode());
        $draft = $this->draft($organization);

        foreach (['admin', 'member', 'viewer'] as $role) {
            $user = $this->member($organization, $role);

            $status = $this->asRequest(fn () => $this->actingAs($user)
                ->withSession(['portal_org_id' => $organization->id, '_token' => 'agree-token'])
                ->post("/portal/invoices/{$draft->id}/issue", ['_token' => 'agree-token'])
                ->status());

            $this->assertNotSame(419, $status);

            $refusedByRole = app(Access::class)->decide($organization, $user, Feature::IssueInvoices)->source === Level::Role;

            $this->assertSame($status === 403, $refusedByRole, $role);
        }
    }

    /** IsOrgAdmin guards webhook changes, and the role level needs an admin. */
    public function test_org_admin_agrees_on_roles(): void
    {
        $organization = $this->organization($this->registry()->defaultCode());

        foreach (['admin', 'member'] as $role) {
            $user = $this->member($organization, $role);
            $token = JWTAuth::claims(['org_id' => $organization->id, 'role' => $role])->fromUser($user);

            $status = $this->withToken($token)->postJson('/api/webhooks', [])->status();
            $refusedByRole = app(Access::class)->decide($organization, $user, Feature::Webhooks)->source === Level::Role;

            $this->assertSame($status === 403, $refusedByRole, $role);
        }
    }

    /**
     * With no override, the partner API's plan check through Access is the
     * licence's own answer, for every tier and every catalogued feature.
     */
    public function test_licence_check_agrees_with_licence(): void
    {
        $organization = $this->organization($this->registry()->defaultCode());

        foreach (LicenseTier::cases() as $tier) {
            $licence = License::create([
                'org_id' => $organization->id,
                'api_key' => 'cp_test_'.$tier->value,
                'api_secret_hash' => License::hashSecret('secret'),
                'organization_name' => 'Acme',
                'contact_email' => 'ops@masaar.test',
                'environment' => 'sandbox',
                'tier' => $tier,
                'status' => 'active',
            ]);

            foreach (Feature::cases() as $feature) {
                if (($key = $feature->licenceFeature()) === null || $key === 'offline_mode') {
                    continue;
                }

                $this->assertSame($licence->hasFeature($key), app(Access::class)->licenceAllows($licence, $feature), "{$tier->value} {$key}");
            }
        }
    }

    private function agreeOnIssuing(string $code, string $scenario, Closure $arrange): void
    {
        $organization = $this->organization($code);
        $invoice = $this->draft($organization);
        $arrange($organization);

        $access = app(Access::class)->decide($organization->fresh(), null, Feature::IssueInvoices);
        $step = $this->stepRefuses($code, $invoice, $organization->fresh());

        $this->assertSame($step, ! $access->allowed, "{$code}, {$scenario}: the step ".($step ? 'refused' : 'passed').", Access said {$access->code}");

        app(KillSwitch::class)->resumeAll();
        config(['licensing.filing_requires_licence' => false]);
    }

    /** Whether the country's issuing step stops at one of its gates. */
    private function stepRefuses(string $code, Invoice $invoice, Organization $organization): bool
    {
        try {
            $this->registry()->get($code)->issuingStep()->prepare($invoice, $organization, false);

            return false;
        } catch (IssuanceRefusedException) {
            return true;
        } catch (ComplianceException $e) {
            return in_array($e->getErrorCode(), self::GATES, true);
        } catch (Throwable) {
            return false;
        }
    }

    private function organization(string $code): Organization
    {
        return Organization::create(['name' => 'Acme '.uniqid(), 'country' => $code, 'status' => Organization::STATUS_ACTIVE]);
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function draft(Organization $organization): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => $this->registry()->get((string) $organization->country)->currency(),
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }

    private function registry(): JurisdictionRegistry
    {
        return app(JurisdictionRegistry::class);
    }
}
