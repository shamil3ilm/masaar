<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A platform admin's switch for one feature of one company: shown in the
 * Features section of the organization page, set only with features.manage,
 * stored with its reason, audited, and honoured on every path that uses it.
 */
class FeatureOverrideTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'features-token';

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->registry()->registered() as $code) {
            app(JurisdictionAvailability::class)->set($code, true);
        }

        $this->acme = $this->organization($this->registry()->defaultCode());
    }

    /**
     * Every feature, its state, the level that decided and the reason; the
     * control only on what can be switched for one company.
     */
    public function test_section_lists_every_feature(): void
    {
        $owner = $this->admin('owner');
        app(FeatureOverrides::class)->set((string) $this->acme->id, Feature::IssueInvoices, 'off', 'Disputed invoices', $owner->id);

        $page = $this->open($owner)->assertOk()->assertSee('Features');

        foreach (Feature::cases() as $feature) {
            $page->assertSee($feature->label());
        }

        $page->assertSee('override_off')
            ->assertSee('Disputed invoices')
            ->assertSee('Switched off by ops@masaar.test', false)
            ->assertSee('Not switchable per company')
            ->assertSee(route('admin.organizations.features', $this->acme->id), false);
    }

    /** Every role reads the section; only one with features.manage changes it. */
    public function test_changing_needs_features_manage(): void
    {
        foreach (['support', 'auditor'] as $role) {
            $admin = $this->admin($role);

            $this->open($admin)->assertOk()->assertSee('Features')->assertDontSee(route('admin.organizations.features', $this->acme->id), false);
            $this->change($admin, 'issue_invoices', 'off')->assertForbidden();
        }

        $this->assertNull(app(FeatureOverrides::class)->state((string) $this->acme->id, Feature::IssueInvoices));
        $this->change($this->admin('operations'), 'issue_invoices', 'off')->assertSessionHas('success');
    }

    /** Stored with who and why, on the audit record with before and after. */
    public function test_switch_is_stored_and_audited(): void
    {
        $owner = $this->admin('owner');

        $this->change($owner, 'issue_invoices', 'off', 'Disputed invoices')->assertRedirect(route('admin.organization.detail', $this->acme->id));

        $switch = app(FeatureOverrides::class)->for((string) $this->acme->id)['issue_invoices'];
        $this->assertSame('off', $switch['state']);
        $this->assertSame('Disputed invoices', $switch['reason']);
        $this->assertSame('ops@masaar.test', $switch['by']);

        $entry = AuditLog::where('action', 'admin.organizations.features')->sole();
        $this->assertSame((string) $owner->id, $entry->user_id);
        $this->assertSame('Organization', $entry->entity_type);
        $this->assertSame((string) $this->acme->id, $entry->entity_id);
        // By key: MySQL stores a JSON object's keys in its own order.
        $byKey = function (array $values): array {
            ksort($values);

            return $values;
        };
        $this->assertSame(['feature' => 'issue_invoices', 'state' => 'default'], $byKey($entry->old_values));
        $this->assertSame(['feature' => 'issue_invoices', 'reason' => 'Disputed invoices', 'state' => 'off'], $byKey($entry->new_values));

        $this->change($owner, 'issue_invoices', 'default', 'Resolved')->assertSessionHas('success');
        $this->assertSame([], app(FeatureOverrides::class)->for((string) $this->acme->id));
    }

    /** Off where every path honours it; on only where the plan is read through Access. */
    public function test_unswitchable_features_are_refused(): void
    {
        $owner = $this->admin('owner');

        $this->change($owner, 'file_with_authority', 'off')->assertSessionHasErrors('feature');
        $this->change($owner, 'issue_invoices', 'on')->assertSessionHasErrors('state');
        $this->change($owner, 'webhooks', 'off')->assertSessionHasErrors('state');
        $this->change($owner, 'issue_invoices', 'off', '')->assertSessionHasErrors('reason');

        $this->assertSame([], app(FeatureOverrides::class)->for((string) $this->acme->id));
    }

    /**
     * Switched off, issuing is refused in every country through the engine
     * - the portal, pipeline, API and billing all reach it - and the draft
     * stays a draft. Back to the default, the switch is gone.
     */
    public function test_switched_off_issuing_is_refused(): void
    {
        foreach ($this->registry()->registered() as $code) {
            $organization = $this->organization($code);
            $invoice = $this->draft($organization);
            app(FeatureOverrides::class)->set((string) $organization->id, Feature::IssueInvoices, 'off', 'Test', null);

            $outcome = app(ComplianceEngine::class)->issue($invoice, $organization, new IssueOptions(file: false));

            $this->assertFalse($outcome->issued, $code);
            $this->assertStringContainsString('switched off for this company', $outcome->errors[0] ?? '', $code);
            $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);

            app(FeatureOverrides::class)->set((string) $organization->id, Feature::IssueInvoices, null, 'Resolved', null);
            $retry = app(ComplianceEngine::class)->issue($invoice->fresh(), $organization, new IssueOptions(file: false));
            $this->assertStringNotContainsString('switched off for this company', implode(' ', $retry->errors), $code);
        }
    }

    /** One kind of document switched off refuses that kind only. */
    public function test_one_document_kind_switched_off(): void
    {
        app(FeatureOverrides::class)->set((string) $this->acme->id, Feature::IssueStandard, 'off', 'Test', null);

        $outcome = app(ComplianceEngine::class)->issue($this->draft($this->acme), $this->acme, new IssueOptions(file: false));
        $this->assertStringContainsString('switched off for this company', $outcome->errors[0] ?? '');

        $simplified = app(ComplianceEngine::class)->issue($this->draft($this->acme, 'simplified'), $this->acme, new IssueOptions(file: false));
        $this->assertStringNotContainsString('switched off for this company', implode(' ', $simplified->errors));
    }

    /** The pipeline switched off is refused before anything is drafted. */
    public function test_switched_off_pipeline_drafts_nothing(): void
    {
        app(FeatureOverrides::class)->set((string) $this->acme->id, Feature::ErpPipeline, 'off', 'Runaway ERP', null);

        try {
            app(PipelineService::class)->submitInvoice(['invoice_type' => 'simplified'], (string) $this->acme->id);
            $this->fail('The pipeline was not refused.');
        } catch (ComplianceException $e) {
            $this->assertSame(ErrorCode::AUTH_FEATURE_SWITCHED_OFF, $e->getErrorCode());
            $this->assertSame(403, $e->getHttpStatus());
        }

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $this->acme->id)->count()));
    }

    /**
     * Granted beyond the plan, the partner API lets a Starter licence write a
     * standard invoice; back to the default it refuses again, with the same
     * error as ever.
     */
    public function test_grant_opens_the_partner_api(): void
    {
        $credential = $this->licence('starter');

        $this->postJson('/api/v1/invoices', ['type' => 'standard'], $credential)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LICENSE_FEATURE_UNAVAILABLE')
            ->assertJsonPath('error.details.feature', 'standard_invoices');

        app(FeatureOverrides::class)->set((string) $this->acme->id, Feature::IssueStandard, 'on', 'Trial', null);
        $this->assertNotSame(403, $this->postJson('/api/v1/invoices', ['type' => 'standard'], $credential)->status());

        app(FeatureOverrides::class)->set((string) $this->acme->id, Feature::IssueStandard, null, 'Trial over', null);
        $this->postJson('/api/v1/invoices', ['type' => 'standard'], $credential)
            ->assertStatus(403)
            ->assertJsonPath('error.details.feature', 'standard_invoices');
    }

    private function organization(string $code): Organization
    {
        return Organization::create(['name' => 'Acme '.uniqid(), 'country' => $code, 'status' => Organization::STATUS_ACTIVE]);
    }

    private function draft(Organization $organization, string $type = 'standard'): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => $type,
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => $this->registry()->get((string) $organization->country)->currency(),
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function licence(string $tier): array
    {
        License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret('secret-acme'),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => $tier,
            'status' => 'active',
            'scopes' => ['invoice.submit', 'invoice.read', 'webhook.manage', 'compliance.status'],
        ]);

        return ['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'secret-acme', 'Accept' => 'application/json'];
    }

    private function admin(string $role): User
    {
        return User::factory()->create([
            'is_platform_admin' => true,
            'platform_role' => $role,
            'email' => $role === 'owner' ? 'ops@masaar.test' : $role.'@masaar.test',
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function open(User $as): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($as)->get('/admin/organizations/'.$this->acme->id));
    }

    private function change(User $as, string $feature, string $state, string $reason = 'Support ticket 42'): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($as)
            ->withSession(['_token' => self::TOKEN])
            ->post('/admin/organizations/'.$this->acme->id.'/features', [
                '_token' => self::TOKEN, 'feature' => $feature, 'state' => $state, 'reason' => $reason,
            ]));
    }

    private function registry(): JurisdictionRegistry
    {
        return app(JurisdictionRegistry::class);
    }
}
