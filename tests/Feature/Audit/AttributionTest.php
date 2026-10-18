<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Attribution\Channel;
use App\Domains\Audit\Attribution\Origin;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Invoice\Models\BusinessQrCode;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who made each record, how it arrived, through which licence and in which
 * request - filled by RecordsActors from Origin, never by the caller - and
 * what is removed being kept, attributed, and able to come back.
 */
class AttributionTest extends TestCase
{
    use RefreshDatabase;

    private const VAT = '310122393500003';

    private Organization $acme;

    private User $admin;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->cashier = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->cashier->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_a_portal_customer_records_its_maker(): void
    {
        $this->portal($this->cashier)->post('/portal/customers', [
            'name' => 'Gulf Supplies',
            'name_ar' => 'مؤسسة الخليج',
            'vat_number' => self::VAT,
            'country_code' => 'SA',
        ])->assertRedirect(route('portal.customers.index'));

        $customer = $this->customers()->firstOrFail();
        $this->assertSame($this->cashier->id, $customer->created_by);
        $this->assertSame($this->cashier->id, $customer->updated_by);
        $this->assertSame(Channel::Portal->value, $customer->created_via);
        $this->assertNull($customer->license_id);
    }

    /**
     * Two people in one organization are told apart: each change says whose
     * it was.
     */
    public function test_each_person_is_told_apart(): void
    {
        $customer = $this->actingAs($this->cashier, 'web')->customer();
        $this->actingAs($this->admin, 'web');
        $customer->forceFill(['name' => 'Gulf Supplies Co'])->save();

        $this->assertSame($this->cashier->id, $customer->fresh()->created_by);
        $this->assertSame($this->admin->id, $customer->fresh()->updated_by);
    }

    public function test_a_pipeline_invoice_records_its_licence(): void
    {
        $license = $this->license();

        $this->withHeaders(['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'test-secret-acme', 'X-Request-Id' => 'trace-0001-attribution'])
            ->postJson('/api/v1/pipeline/submit', [
                'org_id' => $this->acme->id,
                'invoice_number' => 'INV-1',
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'auto_submit' => false,
                'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ])->assertSuccessful();

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->firstOrFail());
        $this->assertSame(Channel::Pipeline->value, $invoice->created_via);
        $this->assertSame($license->id, $invoice->license_id);
        $this->assertNull($invoice->created_by, 'a licence key has no person behind it');
        $this->assertTrue(AuditLog::query()->where('request_id', 'trace-0001-attribution')->exists());
    }

    public function test_a_removed_qr_code_comes_back(): void
    {
        $code = BusinessQrCode::withoutTenantScope(fn () => BusinessQrCode::query()->create([
            'org_id' => $this->acme->id, 'kind' => BusinessQrCode::LINK, 'label' => 'Map', 'data' => ['url' => 'https://example.test'],
        ]));

        $this->portal($this->admin)->post(route('portal.qr-codes.destroy', $code->id))->assertRedirect();

        $removed = BusinessQrCode::withoutTenantScope(fn () => BusinessQrCode::withTrashed()->findOrFail($code->id));
        $this->assertTrue($removed->trashed());
        $this->assertSame($this->admin->id, $removed->deleted_by);

        $this->portal($this->admin)->get(route('portal.qr-codes.index'))->assertOk()->assertSee('data-removed-qr-codes', false);
        $this->portal($this->admin)->post(route('portal.qr-codes.restore', $code->id))->assertRedirect();

        $back = BusinessQrCode::withoutTenantScope(fn () => BusinessQrCode::query()->findOrFail($code->id));
        $this->assertNull($back->deleted_by);
    }

    /**
     * Removing a customer frees its VAT number for a new one, on the unique
     * index as well as in the form's rule.
     */
    public function test_a_removed_customers_number_is_free(): void
    {
        $this->actingAs($this->cashier, 'web')->customer()->delete();

        $this->portal($this->cashier)->post('/portal/customers', [
            'name' => 'Gulf Supplies again',
            'name_ar' => 'مؤسسة الخليج',
            'vat_number' => self::VAT,
            'country_code' => 'SA',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Customer::withoutTenantScope(fn () => Customer::withTrashed()->where('vat_number', self::VAT)->count()));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->customer();
    }

    /**
     * A queued job knows who queued it, and what it writes is the platform's.
     */
    public function test_a_job_keeps_who_queued_it(): void
    {
        // What a job dispatched by the cashier carries, then run with nobody
        // signed in, as a worker runs it.
        $this->actingAs($this->cashier, 'web');
        $this->app['request']->headers->set('X-Request-Id', 'trace-0002-job');
        $payload = json_encode(['illuminate:log:context' => Context::dehydrate()]);
        auth()->guard('web')->logout();
        Context::flush();

        $job = new SyncJob($this->app, (string) $payload, 'sync', 'default');
        $origin = app(Origin::class);

        event(new JobProcessing('sync', $job));

        $this->assertSame($this->cashier->id, $origin->actorId());
        $this->assertSame(Channel::System, $origin->channel());
        $this->assertSame('trace-0002-job', $origin->requestId());

        event(new JobProcessed('sync', $job));
    }

    /**
     * The invoice says which saved customer it is for; its buyer is still
     * what the invoice says, whatever the customer becomes.
     */
    public function test_an_invoice_names_its_saved_customer(): void
    {
        $customer = $this->customer();
        $invoice = $this->draft();

        $this->assertSame($customer->id, $invoice->customer_id);

        $invoice->forceFill(['status' => 'issued', 'issued_at' => now()])->save();
        $customer->forceFill(['name' => 'Renamed'])->save();

        $this->assertSame('Gulf Supplies', $invoice->fresh()->buyer_name);
        $this->assertSame($customer->id, $invoice->fresh()->customer_id);
    }

    public function test_a_membership_records_who_added_it(): void
    {
        $newcomer = User::factory()->create();

        $this->actingAs($this->admin, 'web');
        $newcomer->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->assertSame($this->admin->id, DB::table('organization_user')->where('user_id', $newcomer->id)->value('created_by'));
    }

    public function test_a_deleted_account_is_removed(): void
    {
        $this->actingAs($this->cashier)->post('/account/delete', ['password' => 'password', 'confirm' => 'DELETE'])
            ->assertRedirect(route('login'));

        $gone = User::withTrashed()->findOrFail($this->cashier->id);
        $this->assertTrue($gone->trashed());
        $this->assertSame($this->cashier->id, $gone->deleted_by);
        $this->assertNull(User::query()->find($this->cashier->id));
    }

    /**
     * Evidence is never deleted, and an audit entry never changed (ZATCA
     * Detailed Guidelines p26, p27).
     */
    public function test_evidence_cannot_be_deleted(): void
    {
        $entry = AuditLog::create(['action' => 'test.recorded', 'entity_type' => 'Test']);

        $this->assertThrows(fn () => $entry->delete(), \RuntimeException::class);
        $this->assertThrows(fn () => $entry->forceFill(['action' => 'changed'])->save(), \RuntimeException::class);

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->create([
            'invoice_id' => $this->draft()->id,
            'org_id' => $this->acme->id,
            'state' => 'draft',
            'submission_mode' => 'sync',
        ]));

        $this->assertThrows(fn () => $submission->delete(), \RuntimeException::class);
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(InvoiceSubmission::class));
    }

    private function customer(): Customer
    {
        return Customer::withoutTenantScope(fn () => Customer::query()->create([
            'org_id' => $this->acme->id,
            'name' => 'Gulf Supplies',
            'vat_number' => self::VAT,
            'country_code' => 'SA',
        ]));
    }

    private function draft(): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Gulf Supplies',
            'buyer_vat_number' => self::VAT,
            'subtotal' => '100',
            'tax_amount' => '15',
            'total' => '115',
        ]));
    }

    private function license(): License
    {
        return License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret('test-secret-acme'),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => 'starter',
            'status' => 'active',
            'scopes' => ['invoice.submit', 'compliance.status'],
        ]);
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }

    /**
     * @return Builder<Customer>
     */
    private function customers(): Builder
    {
        return Customer::withoutTenantScope(fn () => Customer::query());
    }
}
