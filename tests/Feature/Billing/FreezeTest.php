<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Services\Freeze;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\Standing;
use App\Domains\Platform\Approvals\Actions\AccountFreezeActions;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Models\ApprovalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\RecordedOriginals;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The overdue-payment freeze: built, shipped off, staged by days past due,
 * per customer or exempt, by hand under approval - and never in the way of
 * a customer's own records or the filing of what it already issued.
 */
class FreezeTest extends TestCase
{
    use RecordedOriginals;
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'status' => 'active',
            'vat_number' => '300000000000003', 'street' => 'King Fahd Road', 'building_number' => '1234',
            'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        License::createWithCredentials([
            'org_id' => $this->acme->id, 'organization_name' => 'Acme Trading', 'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Enterprise, 'status' => LicenseStatus::Active, 'expires_at' => now()->addYear(),
        ]);

        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret',
        ]);

        $this->owner = User::factory()->create();
        $this->owner->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        Http::fake([
            '*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']]),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /** Off at launch: a hundred days late, still in good standing. */
    public function test_the_freeze_ships_off(): void
    {
        $this->overdueBy(100);

        $this->assertFalse(app(FinanceSettings::class)->freezeEnabled());
        $this->assertSame(Standing::GOOD, $this->standing()->stage);
        $this->assertTrue(app(Access::class)->decide($this->acme, $this->owner, Feature::IssueInvoices)->allowed);
    }

    /** Two weeks of reminders, a week's grace, two weeks read-only, then frozen. */
    public function test_stages_follow_days_overdue(): void
    {
        $this->switchOn();

        $stages = [];

        foreach ([0, 1, 14, 15, 21, 22, 35, 36, 200] as $days) {
            Receivable::query()->delete();
            $this->overdueBy($days);
            $stages[$days] = $this->standing()->stage;
        }

        $this->assertSame([
            0 => Standing::GOOD, 1 => Standing::REMINDERS, 14 => Standing::REMINDERS, 15 => Standing::GRACE, 21 => Standing::GRACE,
            22 => Standing::RESTRICTED, 35 => Standing::RESTRICTED, 36 => Standing::FROZEN, 200 => Standing::FROZEN,
        ], $stages);
    }

    /**
     * Restricted is read-only: no new invoice, nobody new - one decision,
     * "Account frozen: payment overdue". Filing, PDFs, the API's reads and
     * notes correcting an issued invoice stay open.
     */
    public function test_restricted_is_one_decision(): void
    {
        $this->switchOn();
        $this->overdueBy(25);
        $access = app(Access::class);

        foreach ([Feature::IssueInvoices, Feature::IssueStandard, Feature::AddUsers] as $feature) {
            $decision = $access->decide($this->acme, $this->owner, $feature);
            $this->assertFalse($decision->allowed, $feature->value.' was allowed while restricted.');
            $this->assertSame('account_frozen', $decision->code);
            $this->assertSame('Account frozen: payment overdue.', $decision->reason);
            $this->assertSame(Standing::RESTRICTED, $decision->detail['stage']);
        }

        foreach ([Feature::FileWithAuthority, Feature::PdfAndEmail, Feature::CreditNotes, Feature::DebitNotes, Feature::Webhooks] as $feature) {
            $this->assertNotSame('account_frozen', $access->decide($this->acme, $this->owner, $feature)->code, $feature->value.' was refused while restricted.');
        }

        // Frozen stops webhooks too, and still none of the kept ones.
        Receivable::query()->delete();
        $this->overdueBy(40);
        $this->assertSame('account_frozen', $access->decide($this->acme, $this->owner, Feature::Webhooks)->code);
        $this->assertNotSame('account_frozen', $access->decide($this->acme, $this->owner, Feature::FileWithAuthority)->code);
    }

    /**
     * Every issuing path asks the same question: a new invoice is refused
     * before a counter is spent, by the module itself.
     */
    public function test_issuing_refuses_a_frozen_account(): void
    {
        $this->freezeByHand();

        $outcome = app(ComplianceEngine::class)->issue($this->draft('ACME-NEW'), $this->acme, new IssueOptions(file: false));

        $this->assertFalse($outcome->issued);
        $this->assertStringContainsString('Account frozen: payment overdue.', implode(' ', $outcome->errors));
    }

    /**
     * Issued before the freeze, filed during it: the authority's deadline is
     * the law's, so the queued filing goes through.
     */
    public function test_a_frozen_account_still_files(): void
    {
        $invoice = $this->draft('ACME-1');
        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);
        $this->freezeByHand();

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id, 'org_id' => $this->acme->id, 'state' => 'queued',
            'submission_type' => 'reporting', 'submission_mode' => 'async',
        ]));
        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);

        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
        $this->assertSame(Standing::FROZEN, $this->standing()->stage);
    }

    /**
     * The ERP pipeline is closed to new invoices, as every other path is,
     * and open to a credit or debit note correcting one already issued -
     * alone or in a batch.
     */
    public function test_the_pipeline_still_takes_notes(): void
    {
        $this->recordedOriginal($this->acme, 'ACME-1', 'simplified');
        $this->freezeByHand();
        License::create([
            'org_id' => $this->acme->id, 'api_key' => 'cp_test_freeze', 'api_secret_hash' => License::hashSecret('secret-freeze'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@acme.test', 'environment' => 'sandbox', 'tier' => 'enterprise',
            'status' => 'active', 'scopes' => ['invoice.submit', 'compliance.status'],
        ]);
        $api = $this->withHeaders(['X-API-Key' => 'cp_test_freeze', 'X-API-Secret' => 'secret-freeze']);
        $sale = ['type' => 'simplified', 'issue_date' => now()->toDateString(), 'auto_submit' => false,
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]]];
        $credit = [...$sale, 'document_type' => 'credit_note', 'billing_ref' => 'ACME-1', 'adjustment_reason' => 'In case of goods or services refund.'];

        $api->postJson('/api/v1/pipeline/submit', [...$sale, 'org_id' => $this->acme->id])
            ->assertJsonPath('error.message', 'Account frozen: payment overdue.');
        $api->postJson('/api/v1/pipeline/submit', [...$credit, 'org_id' => $this->acme->id, 'invoice_number' => 'CN-1'])->assertCreated();
        $api->postJson('/api/v1/pipeline/submit', [...$credit, 'org_id' => $this->acme->id, 'invoice_number' => 'DN-1', 'document_type' => 'debit_note'])->assertCreated();

        $batch = $api->postJson('/api/v1/pipeline/batch', [
            'org_id' => $this->acme->id,
            'invoices' => [[...$sale, 'invoice_number' => 'B-1'], [...$credit, 'invoice_number' => 'B-2']],
        ])->assertStatus(207);

        $this->assertSame([403, 201], array_column($batch->json('data.results'), 'http_status'));
        $this->assertSame('Account frozen: payment overdue.', $batch->json('data.results.0.message'));
    }

    /** Frozen, it still reads and exports everything of its own. */
    public function test_a_frozen_account_reads_its_records(): void
    {
        $invoice = $this->draft('ACME-2');
        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);
        $this->freezeByHand();
        $portal = $this->actingAs($this->owner)->withSession(['portal_org_id' => $this->acme->id]);

        foreach ([
            route('portal.invoices.index'),
            route('portal.invoices.show', $invoice->id),
            route('portal.invoices.export'),
            route('portal.invoices.pdf', $invoice->id),
            route('portal.billing'),
            route('portal.billing.statement'),
            route('portal.billing.statement.csv'),
            route('portal.billing.export'),
            route('portal.reports'),
        ] as $url) {
            $this->assertSame(200, $portal->get($url)->getStatusCode(), $url.' was closed to a frozen account.');
        }

        $portal->get(route('portal.billing'))->assertSee('Account frozen: payment overdue.');
    }

    /** Nobody new while restricted, and the reason says why. */
    public function test_nobody_new_while_frozen(): void
    {
        $this->freezeByHand();

        $this->actingAs($this->owner)->withSession(['portal_org_id' => $this->acme->id])
            ->from(route('portal.team.index'))
            ->post(route('portal.team.invite'), ['email' => 'new@acme.test', 'role' => 'member'])
            ->assertSessionHas('error', 'Account frozen: payment overdue.');
    }

    /** A customer's own days, or none at all: exempt. */
    public function test_a_customer_has_its_own_stages(): void
    {
        $this->switchOn();
        $this->overdueBy(25);
        $settings = app(FinanceSettings::class);

        $settings->setFreezePolicy(['grace_days' => 30], null, (string) $this->acme->id);
        $this->assertSame(Standing::GRACE, $this->standing()->stage);

        $settings->setFreezePolicy(['exempt' => true], null, (string) $this->acme->id);
        $this->assertSame(Standing::GOOD, $this->standing()->stage);

        $settings->setFreezePolicy([], null, (string) $this->acme->id);
        $this->assertSame(Standing::RESTRICTED, $this->standing()->stage);
    }

    /**
     * By hand, with a reason, under the approval policy; unfreezing lifts the
     * policy's freeze until a date, after which the stages decide again.
     */
    public function test_freezing_by_hand_waits_for_approval(): void
    {
        $operations = $this->admin('operations');
        $approver = $this->admin('owner');
        app(ApprovalPolicies::class)->save(AccountFreezeActions::FREEZE, ['enabled' => true, 'approvals_required' => 1, 'approver_roles' => ['owner'], 'expires_after_hours' => 72], null);

        $this->actingAs($operations)->post(route('admin.billing.freeze', $this->acme->id), ['reason' => 'Ninety days unpaid'])->assertRedirect();
        $this->assertSame(Standing::GOOD, $this->standing()->stage);

        $request = ApprovalRequest::query()->where('action', AccountFreezeActions::FREEZE)->sole();
        $this->actingAs($approver)->post(route('admin.approvals.approve', $request->id))->assertRedirect();

        $standing = $this->standing();
        $this->assertSame(Standing::FROZEN, $standing->stage);
        $this->assertTrue($standing->manual);
        $this->assertSame('Ninety days unpaid', $standing->reason);
        $this->assertTrue(AuditLog::query()->where('action', 'finance.account.frozen')->exists());

        $this->actingAs($operations)->post(route('admin.billing.unfreeze', $this->acme->id), ['reason' => 'Paying on Sunday', 'until' => '2026-10-10'])
            ->assertSessionHas('success');
        $this->assertSame(Standing::GOOD, $this->standing()->stage);

        // Past the date, with the policy on and the invoice long overdue.
        $this->switchOn();
        $this->overdueBy(60);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00:00', 'UTC'));
        $this->assertSame(Standing::FROZEN, $this->standing()->stage);
    }

    public function test_the_billing_page_shows_the_standing(): void
    {
        $this->switchOn();
        $this->overdueBy(25);

        $this->actingAs($this->admin('support'))->get(route('admin.billing.show', $this->acme->id))
            ->assertOk()->assertSee('Restricted: read-only')->assertDontSee('Freeze by hand');
        $this->actingAs($this->admin('operations'))->get(route('admin.finance.settings'))
            ->assertOk()->assertSee('Overdue-payment freeze');
    }

    private function standing(): Standing
    {
        return app(Freeze::class)->of($this->acme);
    }

    private function switchOn(): void
    {
        app(FinanceSettings::class)->setFreeze(true, FinanceSettings::FREEZE_DEFAULTS, null);
    }

    private function freezeByHand(): void
    {
        app(Freeze::class)->freeze($this->acme, 'Unpaid since July', null);
    }

    /** An invoice from the platform, unpaid and past due by so many days. */
    private function overdueBy(int $days): void
    {
        $due = CarbonImmutable::now()->startOfDay()->subDays($days);

        Receivable::withoutTenantScope(fn () => Receivable::query()->create([
            'org_id' => $this->acme->id, 'invoice_id' => $this->draft('PLT-'.$days.'-'.uniqid())->id, 'description' => 'Subscription',
            'currency' => 'SAR', 'subtotal' => '100.00', 'vat_amount' => '15.00', 'total' => '115.00', 'tax_currency' => 'SAR', 'vat_amount_tax' => '15.00',
            'issue_date' => $due->subDays(30)->toDateString(), 'due_date' => $due->toDateString(),
            'status' => $days > 0 ? ReceivableStatus::Overdue : ReceivableStatus::Open,
        ]));
    }

    private function draft(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));
        $invoice->lines()->create(['description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1.000', 'unit_price' => '100.00', 'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00']);

        return $invoice;
    }

    private function admin(string $role): User
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
        $admin->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        return $admin;
    }
}
