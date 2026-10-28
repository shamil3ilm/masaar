<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\Events\InvoicePaid;
use App\Domains\Billing\Mail\RenewalReminderMail;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Services\RenewalReminders;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\LanguageGrace;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Payments recorded by hand: renewing the plan, and the platform invoicing
 * them through itself.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private License $licence;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->licence = License::createWithCredentials([
            'org_id' => $this->acme->id,
            'organization_name' => 'Acme Trading',
            'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Starter,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addDays(10),
        ])['license'];

        $this->operations = $this->admin('operations');
    }

    /**
     * Paying before the end adds the months after the current end, not after
     * today: renewing early loses nothing.
     */
    public function test_a_payment_renews_the_plan(): void
    {
        $end = $this->licence->expires_at->copy();

        $this->record()->assertRedirect(route('admin.billing.show', $this->acme->id));

        $this->assertSame($end->copy()->addMonths(12)->toDateString(), $this->licence->fresh()->expires_at->toDateString());

        $payment = Payment::acrossTenants()->firstOrFail();
        $this->assertSame('6000.00', (string) $payment->amount);
        $this->assertSame('900.00', (string) $payment->vat_amount);
        $this->assertSame('6900.00', (string) $payment->total);
        $this->assertSame($end->toDateString(), $payment->period_start->toDateString());
    }

    /**
     * Paying late buys time from today, and the lapsed plan works again.
     */
    public function test_a_lapsed_plan_restarts_today(): void
    {
        $this->licence->forceFill(['expires_at' => now()->subDays(20), 'status' => LicenseStatus::Expired])->save();

        $this->record(['months' => 1]);

        $fresh = $this->licence->fresh();
        $this->assertSame(LicenseStatus::Active, $fresh->status);
        $this->assertSame(now()->addMonth()->toDateString(), $fresh->expires_at->toDateString());
    }

    public function test_a_payment_can_change_plan(): void
    {
        $this->record(['tier' => 'professional']);

        $this->assertSame(LicenseTier::Professional, $this->licence->fresh()->tier);
    }

    public function test_a_suspended_plan_is_not_renewed(): void
    {
        $this->licence->forceFill(['status' => LicenseStatus::Suspended])->save();

        $this->record()->assertSessionHas('error');

        $this->assertSame(0, Payment::acrossTenants()->count());
    }

    public function test_support_cannot_record_payments(): void
    {
        $this->actingAs($this->admin('support'))
            ->post(route('admin.billing.record', $this->acme->id), $this->payment())
            ->assertForbidden();
    }

    /**
     * No billing organization: the payment still counts, and says why it has
     * no invoice.
     */
    public function test_no_billing_organization_is_explained(): void
    {
        config(['billing.organization_id' => null]);

        $this->record()->assertSessionHas('error');

        $payment = Payment::acrossTenants()->firstOrFail();
        $this->assertNull($payment->invoice_id);
        $this->assertStringContainsString('BILLING_ORGANIZATION_ID', (string) $payment->invoice_error);
    }

    /**
     * The billing organization's Arabic seller name is its organizations.name_ar,
     * kept on its company page. Missing, the billing page and the Countries
     * page both say so before a payment finds out.
     */
    public function test_setup_reports_a_missing_arabic_name(): void
    {
        $platform = $this->platform(issues: false);
        $platform->forceFill(['name_ar' => null])->save();
        $owner = $this->admin('owner');

        $this->assertStringContainsString('has no Arabic name', implode(' ', app(Billing::class)->readiness()));
        $this->actingAs($owner)->get(route('admin.billing.show', $this->acme->id))->assertOk()->assertSee('The billing organization has no Arabic name');
        $this->actingAs($owner)->get(route('admin.countries'))->assertOk()->assertSee('The billing organization has no Arabic name');

        $platform->forceFill(['name_ar' => 'منصة '.Brand::name('ar')])->save();
        $this->assertSame([], app(Billing::class)->readiness());
    }

    /**
     * With one, the platform invoices the payer from its own organization.
     */
    public function test_the_platform_invoices_the_payment(): void
    {
        $platform = $this->platform();

        $this->record()->assertSessionHas('success');

        $payment = Payment::acrossTenants()->firstOrFail();
        $invoice = $this->invoiceFor($payment);

        $this->assertSame($platform->id, $invoice->org_id);
        $this->assertSame(InvoiceType::Standard, $invoice->type);
        $this->assertSame('Acme Trading', $invoice->buyer_name);
        $this->assertSame('310122393500003', $invoice->buyer_vat_number);
        $this->assertSame('6000.00', (string) $invoice->subtotal);
        $this->assertSame('900.00', (string) $invoice->tax_amount);
        // KSA-5 to KSA-24: the period the payment bought.
        $this->assertSame($payment->period_start->toDateString(), $invoice->supply_date->toDateString());
        $this->assertSame($payment->period_end->toDateString(), $invoice->supply_end_date->toDateString());
    }

    /**
     * A plan payment pays its invoice in full once, so what waits on a paid
     * invoice - a referral - hears of it once, and not again when the
     * invoicing is retried.
     */
    public function test_a_paid_plan_announces_once(): void
    {
        Event::fake([InvoicePaid::class]);
        $this->platform();

        $this->record()->assertSessionHas('success');

        $payment = Payment::acrossTenants()->firstOrFail();
        app(Billing::class)->invoice($payment->fresh());

        Event::assertDispatchedTimes(InvoicePaid::class, 1);
        Event::assertDispatched(InvoicePaid::class, fn (InvoicePaid $event): bool => $event->organizationId === (string) $this->acme->id
            && $event->paymentId === (string) $payment->id);
    }

    /**
     * A company without a VAT number still gets a standard invoice, naming
     * it by its commercial registration (BR-KSA-81).
     */
    public function test_no_vat_number_uses_the_cr(): void
    {
        $this->acme->forceFill(['vat_number' => null, 'cr_number' => '1010101010'])->save();
        $this->platform();

        $this->record()->assertSessionHas('success');

        $invoice = $this->invoiceFor(Payment::acrossTenants()->firstOrFail());

        $this->assertSame(InvoiceType::Standard, $invoice->type);
        $this->assertNull($invoice->buyer_vat_number);
        $this->assertSame('1010101010', $invoice->buyer_id);
        $this->assertSame('CRN', $invoice->buyer_id_scheme);
    }

    public function test_no_vat_nor_cr_is_explained(): void
    {
        $this->acme->forceFill(['vat_number' => null, 'cr_number' => null])->save();
        $this->platform(issues: false);

        $this->record()->assertSessionHas('error');

        $payment = Payment::acrossTenants()->firstOrFail();
        $this->assertNull($payment->invoice_id);
        $this->assertStringContainsString('neither a VAT number nor a CR number', (string) $payment->invoice_error);
    }

    /**
     * BR-KSA-63: no invoice to a Saudi buyer without its national address,
     * and the reason names what is missing so it can be completed.
     */
    public function test_an_incomplete_address_is_not_invoiced(): void
    {
        $this->acme->forceFill(['district' => null, 'postal_code' => ''])->save();
        $this->platform(issues: false);

        $this->record()->assertSessionHas('error');

        $payment = Payment::acrossTenants()->firstOrFail();
        $this->assertNull($payment->invoice_id);
        $this->assertStringContainsString('postal code, district', (string) $payment->invoice_error);
        $this->assertSame(0, Invoice::acrossTenants()->count());
    }

    /**
     * The platform's own invoice is in Arabic too (ZATCA's E-Invoicing
     * Detailed Guidelines p60, p63): the line, the notes, the buyer's and its
     * own name, the Arabic first.
     */
    public function test_the_invoice_is_in_arabic(): void
    {
        $this->platform();

        $this->record()->assertSessionHas('success');

        $invoice = $this->invoiceFor(Payment::acrossTenants()->firstOrFail());

        $this->assertSame('شركة أكمي للتجارة', $invoice->buyer_name_ar);
        $this->assertStringStartsWith('اشتراك باقة', (string) $invoice->lines[0]->description_ar);
        $this->assertStringStartsWith('تم استلام الدفعة', (string) $invoice->notes);
    }

    /**
     * A buyer without its Arabic name is not invoiced, and the reason says
     * what to add - unless the billing organization has a grace period.
     */
    public function test_a_buyer_without_arabic_waits(): void
    {
        $this->acme->forceFill(['name_ar' => null])->save();
        $platform = $this->platform(issues: false);

        $this->record()->assertSessionHas('error');

        $payment = Payment::acrossTenants()->firstOrFail();
        $this->assertNull($payment->invoice_id);
        $this->assertStringContainsString('lacks the Arabic its country requires (buyer_name_ar)', (string) $payment->invoice_error);

        app(LanguageGrace::class)->set($platform, CarbonImmutable::now()->addMonth(), 'Customers are adding their Arabic names');
        $this->mock(InvoiceIssuer::class, fn ($mock) => $mock->shouldReceive('issue')->once()->andReturn(IssueOutcome::issuedOnly()));

        app(Billing::class)->invoice($payment);

        $this->assertNotNull($payment->fresh()->invoice_id);
    }

    public function test_the_portal_shows_the_plan(): void
    {
        $this->record();
        $owner = User::factory()->create();
        $owner->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.billing'))
            ->assertOk()
            ->assertSee('Starter')
            ->assertSee('6,900.00 SAR')
            // The menu calls it Requests, so the page does too.
            ->assertSee('open a request under Requests')
            ->assertDontSee('under Support');
    }

    /** An unlimited plan read "12 of 4294967295": its stored ceiling. */
    public function test_unlimited_invoices_show_no_ceiling(): void
    {
        $this->licence->update(['invoices_per_month' => License::UNLIMITED]);
        $owner = User::factory()->create();
        $owner->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->assertNull(app(Billing::class)->usage($this->acme->id, $this->licence->fresh())['limit']);
        $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.billing'))
            ->assertOk()
            ->assertDontSee((string) License::UNLIMITED);
    }

    public function test_a_member_cannot_see_billing(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.billing'))
            ->assertForbidden();
    }

    /**
     * The console lists plans ending soon and those just lapsed, and leaves
     * out the ones with time to spare.
     */
    public function test_renewals_lists_plans_to_chase(): void
    {
        $later = Organization::create(['name' => 'Later Ltd', 'country' => 'SA']);
        License::createWithCredentials([
            'org_id' => $later->id,
            'organization_name' => 'Later Ltd',
            'contact_email' => 'ops@later.test',
            'tier' => LicenseTier::Starter,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addDays(200),
        ]);

        $this->actingAs($this->admin('support'))->get(route('admin.billing.renewals'))
            ->assertOk()
            ->assertSee('Acme Trading')
            ->assertSee('in 10 days')
            ->assertDontSee('Later Ltd');
    }

    public function test_admins_are_reminded_before_the_end(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['email' => 'owner@acme.test']);
        $owner->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->licence->forceFill(['expires_at' => now()->addDays(14)->setTime(12, 0)])->save();

        $this->assertSame(1, app(RenewalReminders::class)->send());

        Mail::assertSent(RenewalReminderMail::class, fn (RenewalReminderMail $mail): bool => $mail->hasTo('owner@acme.test') && $mail->days === 14);
    }

    /**
     * The billing organization, onboarded, with issuing stubbed out.
     */
    private function platform(bool $issues = true): Organization
    {
        $platform = Organization::create([
            'name' => Brand::name('en').' Platform',
            'name_ar' => 'منصة '.Brand::name('ar'),
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        config(['billing.organization_id' => $platform->id]);
        $this->mock(InvoiceIssuer::class, fn ($mock) => $issues
            ? $mock->shouldReceive('issue')->once()->andReturn(IssueOutcome::issuedOnly())
            : $mock->shouldNotReceive('issue'));

        return $platform;
    }

    private function invoiceFor(Payment $payment): Invoice
    {
        return Invoice::acrossTenants()->with('lines')->findOrFail($payment->invoice_id);
    }

    /**
     * A payment invoiced after the fifteen days ZATCA allows still counts,
     * and the admin is told it is late.
     */
    public function test_a_late_payment_is_flagged(): void
    {
        $this->record(['paid_at' => now()->subMonths(3)->toDateString()])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'fifteen days'));

        $this->assertTrue(app(Billing::class)->invoicedLate(CarbonImmutable::now()->subMonths(3)));
        $this->assertFalse(app(Billing::class)->invoicedLate(CarbonImmutable::now()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->operations)
            ->post(route('admin.billing.record', $this->acme->id), $this->payment($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payment(array $overrides = []): array
    {
        return array_merge([
            'tier' => 'starter',
            'months' => 12,
            'amount' => '6000',
            'method' => 'bank_transfer',
            'reference' => 'TRF-1001',
            'paid_at' => now()->toDateString(),
        ], $overrides);
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
