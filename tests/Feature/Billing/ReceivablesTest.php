<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Events\ReceivablePaid;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Services\Credits;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\Statements;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\OrganizationReview;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The platform invoicing its customers: in the customer's currency, its VAT
 * in the tax currency at the recorded rate, nothing inside the free month,
 * credit taken first, and the status following what is paid and when.
 */
class ReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $platform;

    private Organization $acme;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));

        $this->platform = Organization::create([
            'name' => Brand::name('en').' Platform',
            'name_ar' => 'منصة '.Brand::name('ar'),
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        config(['billing.organization_id' => $this->platform->id]);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'name_ar' => 'شركة أكمي للتجارة',
            'country' => 'SA',
            'vat_number' => '310122393500003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $this->mock(InvoiceIssuer::class, fn ($mock) => $mock->shouldReceive('issue')->andReturn(IssueOutcome::issuedOnly()));

        $this->operations = $this->admin('operations');
    }

    public function test_a_customer_is_invoiced_in_riyals(): void
    {
        $receivable = $this->issue('1000');

        $this->assertSame(ReceivableStatus::Open, $receivable->status);
        $this->assertSame('SAR', $receivable->currency);
        $this->assertSame('1150.00', (string) $receivable->total);
        $this->assertSame('150.00', (string) $receivable->vat_amount_tax);
        $this->assertNull($receivable->exchange_rate);
        $this->assertSame('2026-10-31', $receivable->due_date->toDateString());

        $invoice = $this->invoiceOf($receivable);
        $this->assertSame((string) $this->platform->id, (string) $invoice->org_id);
        $this->assertSame('2026-10-31', $invoice->due_date->toDateString());
        $this->assertSame('30', $invoice->payment_means_code);
        // KSA-22 in both languages, the Arabic first.
        $this->assertStringStartsWith('تستحق في 2026-10-31', (string) $invoice->payment_terms);
        $this->assertSame('اشتراك شهري', $invoice->lines[0]->description_ar);
    }

    /**
     * BT-6 is SAR whatever the invoice currency (BR-KSA-EN16931-02), so a
     * dirham invoice records its rate and its VAT in riyals at it (BT-111,
     * BR-53): 150 AED of VAT at 1.021 is 153.15 SAR.
     */
    public function test_dirham_invoices_state_riyal_vat(): void
    {
        $this->invoiceInDirhams();

        $receivable = $this->issue('1000', rate: '1.021');

        $this->assertSame('AED', $receivable->currency);
        $this->assertSame('SAR', $receivable->tax_currency);
        $this->assertSame('1.021000', (string) $receivable->exchange_rate);
        $this->assertSame(FinanceSettings::RATE_MANUAL, $receivable->rate_source);
        $this->assertSame('150.00', (string) $receivable->vat_amount);
        $this->assertSame('153.15', (string) $receivable->vat_amount_tax);

        $invoice = $this->invoiceOf($receivable);
        $this->assertSame('AED', $invoice->currency);
        $this->assertSame('1.021000', (string) $invoice->exchange_rate);
    }

    public function test_a_dirham_invoice_needs_a_rate(): void
    {
        $this->invoiceInDirhams();

        try {
            $this->issue('1000');
            $this->fail('A dirham invoice was issued with no rate.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('states its VAT in SAR too', $e->getMessage());
        }

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /** With the table as the source, the day's rate - or the latest before it. */
    public function test_the_rate_table_supplies_the_rate(): void
    {
        $this->invoiceInDirhams();
        app(FinanceSettings::class)->set(FinanceSettings::RATE_SOURCE, FinanceSettings::RATE_TABLE, null);
        app(ExchangeRates::class)->record('AED', CarbonImmutable::parse('2026-09-29'), '1.0211', null);

        $receivable = $this->issue('1000');

        $this->assertSame('1.021100', (string) $receivable->exchange_rate);
        $this->assertSame(FinanceSettings::RATE_TABLE, $receivable->rate_source);
        $this->assertSame('2026-09-29', $receivable->rate_date->toDateString());
    }

    public function test_a_switched_off_currency_waits(): void
    {
        $this->invoiceInDirhams();
        app(FinanceSettings::class)->setCurrencies(['SAR'], 'SAR', null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('AED is switched off');

        $this->issue('1000', rate: '1.02');
    }

    /** Approved ten days ago: free for a month from approval, then billed. */
    public function test_the_free_month_is_not_invoiced(): void
    {
        $this->acme->forceFill(['status' => Organization::STATUS_ACTIVE, 'reviewed_at' => now()->subDays(10)])->save();

        try {
            $this->issue('1000');
            $this->fail('An invoice was issued inside the free month.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('free period until 2026-10-21', $e->getMessage());
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'UTC'));

        try {
            $this->issue('1000', start: '2026-10-15');
            $this->fail('A period inside the free month was invoiced.');
        } catch (DomainException) {
        }

        $this->assertSame(ReceivableStatus::Open, $this->issue('1000', start: '2026-10-21')->status);
    }

    /** An approval with no end date gives the free month; none, an open plan. */
    public function test_approval_without_an_end_is_free(): void
    {
        $pending = Organization::create(['name' => 'Gulf Supplies', 'country' => 'SA', 'status' => Organization::STATUS_PENDING_REVIEW]);

        app(OrganizationReview::class)->approve((string) $pending->id, (string) $this->operations->id, LicenseTier::Starter->value);

        $licence = License::query()->where('org_id', $pending->id)->sole();
        $this->assertSame('2026-11-01', $licence->expires_at->toDateString());

        $other = Organization::create(['name' => 'Gulf Logistics', 'country' => 'SA', 'status' => Organization::STATUS_PENDING_REVIEW]);
        app(FinanceSettings::class)->set(FinanceSettings::FREE_MONTHS, 0, null, (string) $other->id);
        app(OrganizationReview::class)->approve((string) $other->id, (string) $this->operations->id, LicenseTier::Starter->value);

        $this->assertNull(License::query()->where('org_id', $other->id)->sole()->expires_at);
    }

    /** A referral reward, say: the next invoice in its currency takes it. */
    public function test_credit_is_taken_first(): void
    {
        app(Credits::class)->grant((string) $this->acme->id, 'SAR', '200', BillingCredit::REFERRAL, null, 'Referral reward');

        $receivable = $this->issue('1000');

        $this->assertSame('200.00', (string) $receivable->amount_paid);
        $this->assertSame('950.00', $receivable->balance());
        $this->assertSame(ReceivableStatus::PartlyPaid, $receivable->status);
        $this->assertSame('0.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
    }

    public function test_status_follows_payments_and_dates(): void
    {
        Event::fake([ReceivablePaid::class]);
        $receivable = $this->issue('1000');
        $receivables = app(Receivables::class);

        $receivables->settle($receivable, '500', $this->payment('500'), null, null, CarbonImmutable::now(), null);
        $this->assertSame(ReceivableStatus::PartlyPaid, $receivable->fresh()->status);

        // Due 2026-10-31: overdue from the day after.
        $this->assertSame(0, $receivables->refresh(CarbonImmutable::parse('2026-10-31')));
        $this->assertSame(1, $receivables->refresh(CarbonImmutable::parse('2026-11-01')));
        $this->assertSame(ReceivableStatus::Overdue, $receivable->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00', 'UTC'));
        $receivables->settle($receivable, '650', $this->payment('650'), null, null, CarbonImmutable::now(), null);

        $paid = $receivable->fresh();
        $this->assertSame(ReceivableStatus::Paid, $paid->status);
        $this->assertSame('2026-11-03', $paid->paid_on->toDateString());
        Event::assertDispatched(ReceivablePaid::class, fn (ReceivablePaid $event): bool => $event->receivable->is($receivable));
    }

    public function test_more_than_is_owed_is_refused(): void
    {
        $receivable = $this->issue('1000');

        $this->expectException(DomainException::class);

        app(Receivables::class)->settle($receivable, '1150.01', $this->payment('1150.01'), null, null, CarbonImmutable::now(), null);
    }

    /** A plan payment's invoice is on the account, settled by the payment. */
    public function test_a_plan_payment_settles_its_invoice(): void
    {
        License::createWithCredentials([
            'org_id' => $this->acme->id, 'organization_name' => 'Acme Trading', 'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Starter, 'status' => LicenseStatus::Active, 'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->operations)->post(route('admin.billing.record', $this->acme->id), [
            'tier' => 'starter', 'months' => 12, 'amount' => '6000', 'method' => 'bank_transfer', 'reference' => 'TRF-1', 'paid_at' => now()->toDateString(),
        ])->assertSessionHas('success');

        $receivable = Receivable::query()->sole();
        $this->assertSame(ReceivableStatus::Paid, $receivable->status);
        $this->assertSame('6900.00', (string) $receivable->amount_paid);
        $this->assertSame((string) Payment::query()->sole()->id, (string) $receivable->allocations()->sole()->payment_id);
    }

    /** Charged, paid, and the balance after each; the opening carries what came before. */
    public function test_the_statement_runs_to_a_balance(): void
    {
        $first = $this->issue('1000');
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC'));
        app(Receivables::class)->settle($first, '500', $this->payment('500', 'TRF-500'), null, null, CarbonImmutable::now(), null);

        $statements = app(Statements::class);
        $statement = $statements->statement($this->acme, 'SAR', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertSame('0.00', $statement['opening']);
        $this->assertSame(['1150.00', ''], [$statement['lines'][0]['debit'], $statement['lines'][0]['credit']]);
        $this->assertSame(['', '500.00', '650.00'], [$statement['lines'][1]['debit'], $statement['lines'][1]['credit'], $statement['lines'][1]['balance']]);
        $this->assertSame('TRF-500', $statement['lines'][1]['reference']);
        $this->assertSame('650.00', $statement['closing']);

        $later = $statements->statement($this->acme, 'SAR', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-31'));
        $this->assertSame('1150.00', $later['opening']);
        $this->assertCount(1, $later['lines']);
    }

    /** Not yet due, then 1-30, 31-60, 61-90 and over 90 days past due. */
    public function test_ageing_buckets_by_days_late(): void
    {
        $receivable = $this->issue('1000');
        $statements = app(Statements::class);

        $this->assertSame('1150.00', $statements->ageing(CarbonImmutable::parse('2026-10-31'))['SAR']['totals']['current']);
        $this->assertSame('1150.00', $statements->ageing(CarbonImmutable::parse('2026-11-30'))['SAR']['totals']['days_30']);
        $this->assertSame('1150.00', $statements->ageing(CarbonImmutable::parse('2026-12-01'))['SAR']['totals']['days_60']);
        $this->assertSame('1150.00', $statements->ageing(CarbonImmutable::parse('2027-01-29'))['SAR']['totals']['days_90']);
        $this->assertSame('1150.00', $statements->ageing(CarbonImmutable::parse('2027-01-30'))['SAR']['rows'][(string) $this->acme->id]['over_90']);

        app(Receivables::class)->settle($receivable, '1150', $this->payment('1150'), null, null, CarbonImmutable::now(), null);
        $this->assertSame([], $statements->ageing(CarbonImmutable::parse('2027-01-30')));
    }

    public function test_admins_invoice_from_billing_page(): void
    {
        $this->actingAs($this->admin('support'))
            ->post(route('admin.billing.receivables.issue', $this->acme->id), $this->form())
            ->assertForbidden();

        $this->actingAs($this->operations)
            ->post(route('admin.billing.receivables.issue', $this->acme->id), $this->form())
            ->assertRedirect(route('admin.billing.show', $this->acme->id))
            ->assertSessionHas('success');

        $this->assertSame('Monthly subscription, October', Receivable::query()->sole()->description);

        $this->actingAs($this->admin('support'))->get(route('admin.billing.show', $this->acme->id))
            ->assertOk()->assertSee('Monthly subscription, October')->assertSee('1,150.00');
        $this->actingAs($this->admin('support'))->get(route('admin.finance.receivables'))
            ->assertOk()->assertSee('Acme Trading');
        $csv = $this->actingAs($this->admin('support'))->get(route('admin.finance.receivables.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Acme Trading",2026-10-01,2026-10-31,Open,SAR,1000.00,150.00,1150.00', $csv);
    }

    /** A customer's own terms, and back to the platform's when emptied. */
    public function test_a_customer_has_its_own_terms(): void
    {
        $this->actingAs($this->operations)->post(route('admin.billing.terms', $this->acme->id), [
            'payment_terms_days' => 45, 'reminder_days_after' => '3, 10',
        ])->assertSessionHas('success');

        $settings = app(FinanceSettings::class);
        $this->assertSame(45, $settings->paymentTermsDays((string) $this->acme->id));
        $this->assertSame([3, 10], $settings->remindersAfter((string) $this->acme->id));
        $this->assertSame('2026-11-15', $this->issue('100')->due_date->toDateString());

        $this->actingAs($this->operations)->post(route('admin.billing.terms', $this->acme->id), ['payment_terms_days' => ''])->assertSessionHas('success');
        $this->assertSame(30, $settings->paymentTermsDays((string) $this->acme->id));
    }

    public function test_the_customer_reads_its_account(): void
    {
        $receivable = $this->issue('1000');
        $owner = User::factory()->create();
        $owner->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $member = User::factory()->create();
        $member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.billing'))
            ->assertOk()->assertSee('Monthly subscription, October')->assertSee('1,150.00 SAR');
        $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.billing.statement'))
            ->assertOk()->assertSee('Closing balance');
        $csv = $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.billing.statement.csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Closing balance",,,1150.00', $csv);
        $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.billing.statement'))
            ->assertForbidden();

        // Another customer's invoice is not this one's to download.
        $other = Organization::create(['name' => 'Other Co', 'country' => 'SA']);
        $otherOwner = User::factory()->create();
        $otherOwner->organizations()->attach($other->id, ['role' => 'admin', 'status' => 'active']);
        $this->actingAs($otherOwner)->withSession(['portal_org_id' => $other->id])->get(route('portal.billing.receivables.pdf', $receivable->id))
            ->assertNotFound();
    }

    private function invoiceInDirhams(): void
    {
        $settings = app(FinanceSettings::class);
        $settings->setCurrencies(['SAR', 'AED'], 'SAR', null);
        $settings->set(FinanceSettings::CURRENCY, 'AED', null, (string) $this->acme->id);
    }

    private function issue(string $amount, ?string $rate = null, ?string $start = null): Receivable
    {
        return app(Receivables::class)->issue($this->acme, new ReceivableDraft(
            description: 'Monthly subscription, October',
            descriptionAr: 'اشتراك شهري',
            amount: $amount,
            periodStart: $start === null ? null : CarbonImmutable::parse($start),
            periodEnd: $start === null ? null : CarbonImmutable::parse($start)->addMonthNoOverflow()->subDay(),
            exchangeRate: $rate,
        ), (string) $this->operations->id);
    }

    private function invoiceOf(Receivable $receivable): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->findOrFail($receivable->invoice_id));
    }

    private function payment(string $amount, string $reference = 'TRF'): Payment
    {
        return Payment::withoutTenantScope(fn () => Payment::query()->create([
            'org_id' => $this->acme->id, 'tier' => 'starter', 'months' => 1,
            'period_start' => now()->toDateString(), 'period_end' => now()->addMonth()->toDateString(),
            'amount' => $amount, 'vat_amount' => '0', 'total' => $amount, 'currency' => 'SAR',
            'method' => PaymentMethod::BankTransfer, 'reference' => $reference, 'paid_at' => now()->toDateString(),
        ]));
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return ['description' => 'Monthly subscription, October', 'description_ar' => 'اشتراك شهري، أكتوبر', 'amount' => '1000'];
    }

    private function admin(string $role): User
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
        $admin->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }
}
