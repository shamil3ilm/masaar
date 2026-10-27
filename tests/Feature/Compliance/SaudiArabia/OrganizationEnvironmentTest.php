<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitch;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitchActions;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Models\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The ZATCA environment is the organization's, not the deployment's.
 *
 * The developer portal, simulation and production onboard a device afresh,
 * each with its own OTP and CSIDs (E-Invoicing Detailed Guidelines:
 * onboarding). One deployment-wide switch moved every organization at once,
 * and nothing recorded which environment issued a stored CSID.
 */
class OrganizationEnvironmentTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config([
            'fatoora.signing.disk' => 'creds',
            'fatoora.environment' => 'sandbox',
            'fatoora.endpoints.sandbox' => 'https://zatca.test/sandbox',
            'fatoora.endpoints.simulation' => 'https://zatca.test/simulation',
            'fatoora.endpoints.production' => 'https://zatca.test/core',
        ]);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
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

        Http::fake(['*/invoices/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);
    }

    public function test_defaults_to_the_deployment_environment(): void
    {
        $this->assertSame('sandbox', FatooraEnvironment::of($this->acme));
        $this->assertFalse(FatooraEnvironment::chosen($this->acme));
    }

    public function test_credentials_record_their_environment(): void
    {
        $this->storePcsid();

        $this->assertSame('sandbox', app(CredentialStore::class)->get($this->acme->id, null, CredentialStore::PCSID)['environment']);
    }

    /**
     * Its calls go to its own environment's gateway, whatever the default.
     */
    public function test_filing_uses_its_environments_gateway(): void
    {
        app(EnvironmentSwitch::class)->set($this->acme, 'simulation', null);
        $this->acme->update(['compliance_profile' => [...$this->acme->compliance_profile, 'zatca_onboarded' => true]]);
        $this->storePcsid();
        $invoice = $this->issued('SIM-1');

        app(Submitter::class)->submit($invoice, $this->acme->fresh());

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://zatca.test/simulation/invoices/reporting'));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://zatca.test/sandbox'));
    }

    public function test_onboarding_asks_its_environment(): void
    {
        app(EnvironmentSwitch::class)->set($this->acme, 'simulation', null);
        $compliance = $this->selfSignedCredentials(serial: 5);
        Http::fake(['*/compliance' => Http::response(['binarySecurityToken' => $compliance['certificate'], 'secret' => 's', 'requestID' => 1])]);

        app(OrganizationOnboarding::class)->requestCcsid($this->acme->fresh(), '123456', ['common_name' => 'EGS1', 'serial_number' => '1-x|2-1|3-1']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://zatca.test/simulation/compliance');
        $this->assertSame('simulation', app(CredentialStore::class)->get($this->acme->id, null, CredentialStore::CCSID)['environment']);
    }

    /**
     * A CSID another environment issued is never used, never deleted.
     */
    public function test_switching_archives_and_unboards(): void
    {
        $this->storePcsid();
        $branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id, 'name' => 'Jeddah', 'device_serial' => 'EGS-JED', 'industry' => 'Retail',
            'street' => 'Corniche Road', 'building_number' => '4321', 'district' => 'Al Hamra', 'city' => 'Jeddah', 'postal_code' => '23234',
            'onboarding_status' => Branch::STATUS_ACTIVE,
        ]));

        app(EnvironmentSwitch::class)->set($this->acme, 'simulation', null);

        $fresh = $this->acme->fresh();
        $this->assertSame('simulation', FatooraEnvironment::of($fresh));
        $this->assertFalse($fresh->compliance_profile['zatca_onboarded']);
        $this->assertNull(app(CredentialStore::class)->get($this->acme->id, null, CredentialStore::PCSID));
        $this->assertNotEmpty(Storage::disk('creds')->allFiles("zatca/{$this->acme->id}/archive"), 'the old credentials were not kept');
        $this->assertSame(Branch::STATUS_PENDING, app(Tills::class)->find($this->acme->id, $branch->id)->onboarding_status);
        $this->assertFalse(app(OrganizationOnboarding::class)->status($fresh)['is_onboarded']);

        $entry = AuditLog::query()->where('action', EnvironmentSwitch::AUDIT_ACTION)->sole();
        $this->assertSame(['sandbox', 'simulation'], [$entry->old_values['environment'], $entry->new_values['environment']]);
    }

    /**
     * Not while a document signed in the current environment waits to be
     * filed there: it could never be filed from the new one.
     */
    public function test_switch_waits_for_unfiled_documents(): void
    {
        $this->storePcsid();
        $invoice = $this->issued('WAIT-1');

        try {
            app(EnvironmentSwitch::class)->set($this->acme->fresh(), 'simulation', null);
            $this->fail('the environment switched with a document unfiled');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('WAIT-1', $e->getMessage());
        }

        $this->assertSame('sandbox', FatooraEnvironment::of($this->acme->fresh()));
        $this->assertNotNull(app(CredentialStore::class)->get($this->acme->id, null, CredentialStore::PCSID), 'credentials were archived anyway');
        $this->assertSame(1, AuditLog::query()->where('action', EnvironmentSwitch::REFUSED_ACTION)->count());

        $owner = User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => 'owner',
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
        $this->asRequest(fn (): TestResponse => $this->actingAs($owner)->get(route('admin.organizations.zatca-environment', $this->acme->id)))
            ->assertOk()->assertSee('WAIT-1')->assertSee('data-unfiled', false);

        Invoice::withoutTenantScope(fn () => $invoice->forceFill(['status' => 'accepted'])->saveQuietly());
        app(EnvironmentSwitch::class)->set($this->acme->fresh(), 'simulation', null);

        $this->assertSame('simulation', FatooraEnvironment::of($this->acme->fresh()));
    }

    /**
     * Even one left in place - a deployment default changed under it.
     */
    public function test_another_environments_credential_is_unused(): void
    {
        $this->storePcsid();

        config(['fatoora.environment' => 'production']);

        $this->assertNull(app(CredentialStore::class)->get($this->acme->id, null, CredentialStore::PCSID));
    }

    public function test_platform_admin_switches_it(): void
    {
        $owner = User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => 'owner',
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
        $url = route('admin.organizations.zatca-environment', $this->acme->id);

        $this->asRequest(fn (): TestResponse => $this->actingAs($owner)->get($url))->assertOk()->assertSee('data-zatca-environment="sandbox"', false);
        $this->asRequest(fn (): TestResponse => $this->actingAs($owner)->withSession(['_token' => 'env-token'])->post(route('admin.organizations.zatca-environment.update', $this->acme->id), ['environment' => 'simulation', '_token' => 'env-token']))
            ->assertRedirect($url);

        $this->assertSame('simulation', FatooraEnvironment::of($this->acme->fresh()));
        $this->assertSame($owner->id, AuditLog::query()->where('action', EnvironmentSwitch::AUDIT_ACTION)->sole()->user_id);
    }

    /**
     * A switch to or from production is maker-checker: one admin asks, a
     * second approves, and only then does it happen.
     */
    public function test_production_switch_waits_for_approval(): void
    {
        $owner = $this->platformAdmin('owner');
        $second = $this->platformAdmin('owner');

        $this->switchTo($owner, 'production', 'Pilot goes live')->assertRedirect();

        $this->assertSame('sandbox', FatooraEnvironment::of($this->acme->fresh()));
        $request = ApprovalRequest::platform()->sole();
        $this->assertSame([EnvironmentSwitchActions::PRODUCTION, ApprovalRequest::PENDING], [$request->action, $request->status]);

        app(Approvals::class)->approve((string) $request->id, $second);

        $this->assertSame('production', FatooraEnvironment::of($this->acme->fresh()));
        $this->assertSame($owner->id, AuditLog::query()->where('action', EnvironmentSwitch::AUDIT_ACTION)->sole()->user_id);
    }

    /** With no other approver, it goes through, recorded as bypassed. */
    public function test_lone_admin_bypass_is_recorded(): void
    {
        $owner = $this->platformAdmin('owner');

        $this->switchTo($owner, 'production', 'Only admin')->assertRedirect();

        $this->assertSame('production', FatooraEnvironment::of($this->acme->fresh()));
        $this->assertTrue(AuditLog::query()->where('action', 'security.approval.bypassed')->exists());
    }

    /** Between the two test environments, nothing waits. */
    public function test_sandbox_to_simulation_is_direct(): void
    {
        $owner = $this->platformAdmin('owner');
        $this->platformAdmin('owner');

        $this->switchTo($owner, 'simulation', null);

        $this->assertSame('simulation', FatooraEnvironment::of($this->acme->fresh()));
        $this->assertSame(0, ApprovalRequest::platform()->count());
    }

    private function platformAdmin(string $role): User
    {
        return User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => $role,
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function switchTo(User $admin, string $environment, ?string $reason): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($admin)->withSession(['_token' => 'env-token'])
            ->post(route('admin.organizations.zatca-environment.update', $this->acme->id), array_filter(['environment' => $environment, 'approval_reason' => $reason, '_token' => 'env-token'])));
    }

    public function test_organization_admin_cannot_switch_it(): void
    {
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->asRequest(fn (): TestResponse => $this->actingAs($admin)->withSession(['_token' => 'env-token'])->post(route('admin.organizations.zatca-environment.update', $this->acme->id), ['environment' => 'production', '_token' => 'env-token']));

        $this->assertSame('sandbox', FatooraEnvironment::of($this->acme->fresh()));
    }

    public function test_portal_names_the_environment(): void
    {
        app(EnvironmentSwitch::class)->set($this->acme, 'simulation', null);
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->asRequest(fn (): TestResponse => $this->actingAs($admin)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.zatca.show')))
            ->assertOk()
            ->assertSee('data-zatca-environment="simulation"', false)
            ->assertSee(__('Sign in to the Fatoora portal\'s Simulation environment, choose to onboard a new solution unit, and generate one OTP.'))
            ->assertSee((string) config('fatoora.portal_url'), false);
    }

    private function storePcsid(): void
    {
        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);
    }

    private function issued(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));
        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme->fresh());

        return $invoice->fresh(['lines']);
    }
}
