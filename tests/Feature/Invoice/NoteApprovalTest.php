<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Models\ApprovalRequest;
use App\Domains\Platform\Services\KillSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Credit and debit notes need a second person, when the organization says so.
 *
 * An unchecked note takes output VAT straight off the return, and the law
 * limits notes to real events - a cancellation, a change in consideration,
 * a return (IR Art 40(1)) - so a second pair of eyes is the internal control
 * an auditor expects. Off by default; on, a member's note waits for another
 * administrator, who approves (then it is issued, every check run again) or
 * rejects it. Nobody approves their own. With nobody else who could approve,
 * it goes through and the record says so, as the platform's own approvals do.
 */
class NoteApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'note-approval';

    private Organization $acme;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $code = app(JurisdictionRegistry::class)->defaultCode();
        app(JurisdictionAvailability::class)->set($code, true);

        $this->acme = $this->organization('Acme', $code);
        $this->maker = $this->person('member');
        $this->checker = $this->person('admin');
    }

    public function test_off_by_default_it_issues(): void
    {
        $note = $this->note();

        $this->issue($this->maker, $note)->assertSessionMissing('error');

        $this->assertNotSame(InvoiceStatus::Draft, $note->fresh()->status);
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_on_a_note_waits(): void
    {
        $this->policy(true);
        $note = $this->note();

        $this->issue($this->maker, $note)->assertSessionHas('success', fn (string $m): bool => str_contains($m, __('waits for approval')));

        $this->assertSame(InvoiceStatus::Draft, $note->fresh()->status);
        $request = ApprovalRequest::query()->sole();
        $this->assertSame([ApprovalRequest::PENDING, $this->acme->id, $note->id, $this->maker->id], [$request->status, $request->org_id, $request->target_id, $request->requested_by]);
        $this->assertTrue(DB::table('audit_logs')->where('org_id', $this->acme->id)->where('action', 'approval.requested')->exists());
    }

    public function test_a_second_request_is_not_made(): void
    {
        $this->policy(true);
        $note = $this->note();

        $this->issue($this->maker, $note);
        $this->issue($this->maker, $note);

        $this->assertSame(1, ApprovalRequest::query()->count());
    }

    public function test_the_maker_cannot_approve(): void
    {
        $this->policy(true);
        $maker = $this->person('admin');
        $note = $this->note();
        $this->issue($maker, $note);

        $this->decide($maker, 'approve')->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Draft, $note->fresh()->status);
        $this->assertSame(ApprovalRequest::PENDING, ApprovalRequest::query()->sole()->status);
    }

    public function test_a_member_cannot_approve(): void
    {
        $this->policy(true);
        $note = $this->note();
        $this->issue($this->maker, $note);

        $this->decide($this->person('member'), 'approve')->assertSessionHas('error');

        $this->assertSame(ApprovalRequest::PENDING, ApprovalRequest::query()->sole()->status);
    }

    public function test_approval_issues_the_note(): void
    {
        $this->policy(true);
        $note = $this->note();
        $this->issue($this->maker, $note);

        $this->decide($this->checker, 'approve')->assertSessionHas('success');

        $note->refresh();
        $this->assertNotSame(InvoiceStatus::Draft, $note->status);
        $this->assertSame($this->maker->id, $note->issued_by, 'the maker issued it; the checker approved it');
        $request = ApprovalRequest::query()->sole();
        $this->assertSame([ApprovalRequest::EXECUTED, $this->checker->id], [$request->status, $request->approved_by]);
        $this->assertTrue(DB::table('audit_logs')->where('org_id', $this->acme->id)->where('action', 'approval.executed')->exists());
    }

    public function test_rejection_keeps_the_draft(): void
    {
        $this->policy(true);
        $note = $this->note();
        $this->issue($this->maker, $note);

        $this->decide($this->checker, 'reject', ['reason' => 'Not a real return'])->assertSessionHas('success');

        $this->assertSame(InvoiceStatus::Draft, $note->fresh()->status);
        $this->assertSame(ApprovalRequest::REJECTED, ApprovalRequest::query()->sole()->status);
    }

    /** Every check runs again when it is approved: a stop since then holds. */
    public function test_approval_reruns_every_check(): void
    {
        $this->policy(true);
        $note = $this->note();
        $this->issue($this->maker, $note);

        app(KillSwitch::class)->enable(KillSwitch::SWITCH_ISSUANCE, reason: 'Signing defect');
        $this->decide($this->checker, 'approve');
        app(KillSwitch::class)->resumeAll();

        $this->assertSame(InvoiceStatus::Draft, $note->fresh()->status);
        $this->assertSame(ApprovalRequest::FAILED, ApprovalRequest::query()->sole()->status);
    }

    /** What was approved is what is issued: a changed draft is not. */
    public function test_a_changed_note_is_not_issued(): void
    {
        $this->policy(true);
        $note = $this->note();
        $this->issue($this->maker, $note);

        Invoice::withoutTenantScope(fn () => $note->update(['subtotal' => '900.00', 'total' => '1035.00']));
        $this->decide($this->checker, 'approve');

        $this->assertSame(InvoiceStatus::Draft, $note->fresh()->status);
        $this->assertSame(ApprovalRequest::FAILED, ApprovalRequest::query()->sole()->status);
    }

    public function test_below_the_threshold_it_issues(): void
    {
        $this->policy(true, '1000.00');
        $note = $this->note();

        $this->issue($this->maker, $note);

        $this->assertNotSame(InvoiceStatus::Draft, $note->fresh()->status);
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    /** Nobody else could approve: it goes through, on the record and on screen. */
    public function test_a_sole_user_bypasses_on_record(): void
    {
        $solo = $this->organization('Solo', (string) $this->acme->country);
        $this->acme = $solo;
        $owner = $this->person('owner');
        $this->policy(true);
        $note = $this->note();

        $this->issue($owner, $note)->assertSessionHas('warning', fn (string $m): bool => str_contains($m, __('Nobody else in your organization can approve notes')));

        $this->assertNotSame(InvoiceStatus::Draft, $note->fresh()->status);
        $this->assertTrue(DB::table('audit_logs')->where('org_id', $solo->id)->where('action', 'approval.bypassed')->exists());
    }

    public function test_the_approvals_page_lists_it(): void
    {
        $this->policy(true);
        $note = $this->note();
        $this->issue($this->maker, $note);

        $this->asRequest(fn (): TestResponse => $this->portal($this->checker)->get(route('portal.approvals.index')))
            ->assertOk()->assertSee('CN-1')->assertSee(__('Approve'));
        $this->asRequest(fn (): TestResponse => $this->portal($this->maker)->get(route('portal.approvals.index')))
            ->assertOk()->assertSee('CN-1')->assertDontSee(route('portal.approvals.approve', ApprovalRequest::query()->sole()->id), false);
    }

    /**
     * While it waits, the draft offers no Issue or Edit draft - a second
     * Issue only says it waits - and says where it stands instead (H-72).
     */
    public function test_waiting_note_hides_its_actions(): void
    {
        $this->policy(true);
        $note = $this->note();
        $this->issue($this->maker, $note);

        $this->asRequest(fn (): TestResponse => $this->portal($this->maker)->get(route('portal.invoices.show', $note->id)))
            ->assertOk()
            ->assertSee(__('Awaiting approval'))
            ->assertDontSee(route('portal.invoices.issue', $note->id), false)
            ->assertDontSee(route('portal.invoices.edit', $note->id), false);
    }

    public function test_settings_offer_the_policy(): void
    {
        $this->asRequest(fn (): TestResponse => $this->portal($this->checker)->get(route('portal.settings.edit')))
            ->assertOk()
            ->assertSee(__('Credit and debit notes need a second person'))
            ->assertSee(__('Only notes of at least this total'));
    }

    /** The console's approvals are the platform's: an organization's stay out. */
    public function test_the_console_never_sees_it(): void
    {
        $this->policy(true);
        $this->issue($this->maker, $this->note());

        $operator = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
        $lists = app(Approvals::class)->lists($operator);

        $this->assertCount(0, $lists['waiting']);
        $this->assertCount(0, $lists['history']);
        $this->assertNull(app(Approvals::class)->find(ApprovalRequest::query()->sole()->id));
    }

    private function policy(bool $on, ?string $threshold = null): void
    {
        $customizations = app(Customizations::class);
        $customizations->set('approvals.notes', Level::Organization, Scope::organization((string) $this->acme->id), $on, null);

        if ($threshold !== null) {
            $customizations->set('approvals.notes_threshold', Level::Organization, Scope::organization((string) $this->acme->id), $threshold, null);
        }
    }

    private function organization(string $name, string $country): Organization
    {
        return Organization::create([
            'name' => $name, 'country' => $country, 'status' => Organization::STATUS_ACTIVE,
            'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
    }

    private function person(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function note(): Invoice
    {
        $currency = app(JurisdictionRegistry::class)->get((string) $this->acme->country)->currency();
        $original = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id, 'invoice_number' => 'ACME-1', 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => 'accepted', 'issue_date' => now()->subDay()->toDateString(), 'currency' => $currency,
            'hash' => base64_encode(hash('sha256', 'ACME-1', true)), 'icv' => 1,
            'subtotal' => '500.00', 'tax_amount' => '75.00', 'total' => '575.00',
        ]));

        $note = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id, 'invoice_number' => 'CN-1', 'type' => 'simplified', 'document_type' => 'credit_note',
            'billing_ref' => $original->invoice_number, 'adjustment_reason' => AdjustmentReason::values()[3],
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => $currency,
            'buyer_name' => 'Gulf Supplies', 'buyer_name_ar' => 'الخليج للتوريدات',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));

        InvoiceLine::query()->create([
            'invoice_id' => $note->id, 'description' => 'Returned hour', 'description_ar' => 'ساعة مرتجعة', 'quantity' => '1',
            'unit_code' => 'HUR', 'unit_price' => '100.00', 'net_amount' => '100.00', 'tax_rate' => '15', 'tax_amount' => '15.00',
            'tax_category' => 'S', 'line_total' => '115.00',
        ]);

        return $note->fresh();
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id, '_token' => self::TOKEN]);
    }

    private function issue(User $user, Invoice $note): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->portal($user)
            ->post(route('portal.invoices.issue', $note->id), ['_token' => self::TOKEN]));
    }

    /**
     * @param  array<string, string>  $input
     */
    private function decide(User $user, string $decision, array $input = []): TestResponse
    {
        $id = ApprovalRequest::query()->sole()->id;

        return $this->asRequest(fn (): TestResponse => $this->portal($user)
            ->post(route('portal.approvals.'.$decision, $id), ['_token' => self::TOKEN, ...$input]));
    }
}
