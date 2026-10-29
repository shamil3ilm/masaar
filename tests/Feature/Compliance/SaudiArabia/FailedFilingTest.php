<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionLedger;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\RuleMeanings;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A filing that did not succeed says so, in words that follow its state,
 * and can be sent again by hand where - and only where - that is safe.
 *
 * - The queue's backend unreachable: the invoice stays issued, its
 *   submission stays queued with the reason, and nobody is told it was filed.
 * - Rejected is a verdict: it is not "retried". Failed is not: it is.
 * - A failed submission is sent again from the portal (the organization's
 *   administrators) or the console, audited; a rejected one, or a clearance
 *   that may already be with ZATCA, never is.
 * - The late-reporting page counts a simplified invoice that was never
 *   reported, before the hourly sweep has recorded it.
 */
class FailedFilingTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const TOKEN = 'failed-filing';

    private Organization $acme;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'fatoora.retry_attempts' => 1]);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->mock(Connectivity::class, fn ($mock) => $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false));
    }

    /** H-51: the queue's backend refusing the job is not "filed". */
    public function test_unreachable_queue_is_not_filed(): void
    {
        config(['queue.connections.unreachable' => ['driver' => 'unreachable'], 'fatoora.queue.connection' => 'unreachable']);
        $invoice = $this->draft('Q-1');

        $this->issue($this->admin, $invoice)
            ->assertSessionHas('warning', fn (string $m): bool => str_contains($m, 'did not succeed') && ! str_contains($m, 'filed with ZATCA'))
            ->assertSessionMissing('success');

        $submission = $this->submissionOf($invoice);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame('queued', $submission->state, 'a dispatch failure is retried, so it stays queued');
        $this->assertNotNull($submission->last_error);

        $this->page($this->admin, $invoice)->assertSee(__('Waiting to be sent'))->assertSee((string) $submission->last_error);
    }

    /** H-54: a rejection is a verdict, and is not "retried". */
    public function test_rejection_is_not_called_retried(): void
    {
        Queue::fake();
        Http::fake(['*/invoices/clearance/*' => Http::response([
            'validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-08', 'message' => 'seller id']]],
            'clearanceStatus' => 'NOT_CLEARED',
        ], 400)]);
        $invoice = $this->draft('X-1', standard: true);

        $this->issue($this->admin, $invoice)
            ->assertSessionHas('warning', fn (string $m): bool => str_contains($m, 'rejected') && ! str_contains($m, 'retried'));

        $this->assertSame(InvoiceStatus::Rejected, $invoice->fresh()->status);
        $this->page($this->admin, $invoice)->assertDontSee(route('portal.submissions.retry', $this->submissionOf($invoice)->id), false);
    }

    /** The submissions list says why in plain words; ZATCA's text is folded. */
    public function test_submissions_list_explains_a_rejection(): void
    {
        Queue::fake();
        Http::fake(['*/invoices/clearance/*' => Http::response([
            'validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-08', 'message' => 'seller id']]],
            'clearanceStatus' => 'NOT_CLEARED',
        ], 400)]);
        $this->issue($this->admin, $this->draft('X-2', standard: true));

        $this->asRequest(fn (): TestResponse => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.submissions')))
            ->assertOk()
            ->assertSee(RuleMeanings::explain('BR-KSA-08'))
            ->assertSee('<details class="mt-1 text-xs" data-filing-error>', false)
            ->assertSee('seller id');
    }

    /** A queue that runs at once: the message follows where the filing ended. */
    public function test_sync_queue_message_follows_state(): void
    {
        config(['fatoora.queue.connection' => 'sync']);
        Http::fake(['*/invoices/reporting/*' => Http::sequence()
            ->push(['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-08', 'message' => 'seller id']]], 'reportingStatus' => 'NOT_REPORTED'], 400)
            ->push([], 413)]);

        $this->issue($this->admin, $this->draft('S-1'))
            ->assertSessionHas('warning', fn (string $m): bool => str_contains($m, 'rejected') && ! str_contains($m, 'queued'));
        $this->issue($this->admin, $this->draft('S-2'))
            ->assertSessionHas('warning', fn (string $m): bool => str_contains($m, 'did not succeed') && ! str_contains($m, 'queued'));
    }

    /** H-59: an administrator sends a failed submission again, on the record. */
    public function test_admin_resends_a_failed_submission(): void
    {
        Queue::fake();
        $invoice = $this->issued('F-1');
        $submission = $this->failed($invoice);
        Http::fake(['*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);

        $this->page($this->admin, $invoice)->assertSee(route('portal.submissions.retry', $submission->id), false);
        $this->retry($this->admin, $submission)->assertSessionHas('success');

        $this->assertSame('reported', $submission->fresh()->state);
        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'zatca.submission.resent')->where('entity_id', $submission->id)->exists());
    }

    public function test_a_member_cannot_resend(): void
    {
        $submission = $this->failed($this->issued('F-2'));

        $this->retry($this->member, $submission)->assertForbidden();
        $this->assertSame('failed', $submission->fresh()->state);
    }

    /** Only from failed: a rejection, and a hold for reconciliation, never. */
    public function test_only_failed_is_ever_resent(): void
    {
        Http::fake();
        $invoice = $this->issued('F-3', standard: true);
        $submission = $this->failed($invoice);
        app(SubmissionLedger::class)->transition($submission, 'pending_submission', 'test');
        app(SubmissionLedger::class)->transition($submission, 'submitted', 'test');

        $this->retry($this->admin, $submission)->assertSessionHas('error');
        $this->assertSame('submitted', $submission->fresh()->state);
        Http::assertNothingSent();
    }

    /**
     * Held past the safe delay, an administrator may check it with ZATCA by
     * sending the same signed document again; ZATCA's answer is recorded.
     */
    public function test_admin_resends_a_held_clearance(): void
    {
        Queue::fake();
        $invoice = $this->issued('H-2', standard: true);
        $submission = $this->failed($invoice);
        app(SubmissionLedger::class)->transition($submission, 'pending_submission', 'test');
        app(SubmissionLedger::class)->transition($submission, 'submitted', 'test');
        InvoiceSubmission::acrossTenants()->whereKey($submission->id)
            ->toBase()->update(['state_changed_at' => now()->subHour()]);
        Http::fake(['*/invoices/clearance/*' => Http::response(['clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS']])]);

        $this->page($this->admin, $invoice)->assertSee(route('portal.submissions.retry', $submission->id), false);
        $this->retry($this->admin, $submission)->assertSessionHas('success');

        $this->assertSame('cleared', $submission->fresh()->state);
        Http::assertSent(fn (Request $request): bool => $request['invoice'] === base64_encode((string) $invoice->signed_xml));
    }

    /** A clearance that may have reached ZATCA is held as sent, not failed. */
    public function test_ambiguous_clearance_stays_held(): void
    {
        Queue::fake();
        Http::fake(['*/invoices/clearance/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds')]);

        $result = app(SubmissionTracker::class)->submit($this->issued('H-1', standard: true));

        $submission = InvoiceSubmission::acrossTenants()->findOrFail($result['submission_id']);
        $this->assertSame('submitted', $submission->state);
        $this->assertFalse($submission->isResendable());
    }

    public function test_console_resends_a_failed_submission(): void
    {
        Queue::fake();
        $operator = User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => 'owner',
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
        $submission = $this->failed($this->issued('F-4'));
        Http::fake(['*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);

        $this->asRequest(fn (): TestResponse => $this->actingAs($operator)
            ->withSession(['_token' => self::TOKEN])
            ->post(route('admin.submissions.retry', $submission->id), ['_token' => self::TOKEN]))
            ->assertRedirect();

        $this->assertSame('reported', $submission->fresh()->state);
    }

    /** The late page says a simplified invoice was never reported. */
    public function test_late_page_counts_unreported_invoices(): void
    {
        $this->failed($this->issued('L-1'));

        $this->asRequest(fn (): TestResponse => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.zatca.late')))
            ->assertOk()
            ->assertSee('L-1')
            ->assertDontSee(__('Every simplified invoice has been reported within 24 hours.'));
    }

    private function submissionOf(Invoice $invoice): InvoiceSubmission
    {
        return InvoiceSubmission::acrossTenants()->where('invoice_id', $invoice->id)->latest('created_at')->firstOrFail();
    }

    private function failed(Invoice $invoice): InvoiceSubmission
    {
        $ledger = app(SubmissionLedger::class);
        $submission = $ledger->open([
            'invoice_id' => $invoice->id, 'org_id' => $this->acme->id, 'state' => 'queued',
            'submission_type' => $invoice->requiresClearance() ? 'clearance' : 'reporting', 'submission_mode' => 'async',
        ], 'test');
        $ledger->transition($submission, 'failed', 'test', [], ['last_error' => 'Class "Redis" not found', 'last_error_code' => 'SYS_001']);

        return $submission->fresh();
    }

    private function issue(User $user, Invoice $invoice): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['portal_org_id' => $this->acme->id, '_token' => self::TOKEN])
            ->post(route('portal.invoices.issue', $invoice->id), ['_token' => self::TOKEN]));
    }

    private function retry(User $user, InvoiceSubmission $submission): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['portal_org_id' => $this->acme->id, '_token' => self::TOKEN])
            ->post(route('portal.submissions.retry', $submission->id), ['_token' => self::TOKEN]));
    }

    private function page(User $user, Invoice $invoice): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.invoices.show', $invoice->id)))->assertOk();
    }

    private function draft(string $number, bool $standard = false): Invoice
    {
        $buyer = $standard ? [
            'buyer_name' => 'Beta Industries', 'buyer_name_ar' => 'شركة بيتا', 'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
        ] : [];

        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => $standard ? 'standard' : 'simplified',
            'document_type' => 'invoice', 'status' => 'draft', 'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00', ...$buyer,
        ]);

        $invoice->lines()->create([
            'description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    private function issued(string $number, bool $standard = false): Invoice
    {
        $invoice = $this->draft($number, $standard);
        app(Submitter::class)->generate($invoice, $this->acme);

        return $invoice->fresh(['lines']);
    }
}
