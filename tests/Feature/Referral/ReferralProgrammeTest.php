<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Contracts\CreditSource;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\AccountCredit;
use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\OrganizationReview;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Referral\DTOs\ReferralTerms;
use App\Domains\Referral\Enums\ReferralStatus;
use App\Domains\Referral\Enums\RewardStatus;
use App\Domains\Referral\Models\Referral;
use App\Domains\Referral\Services\ReferralCodes;
use App\Domains\Referral\Services\Referrals;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The referral programme: off until an admin turns it on; a code at
 * sign-up; abuse checks; rewards once the company qualifies, as credit on
 * the next invoice or months on the plan; caps; and an admin's approval,
 * adjustment and reversal, all audited.
 */
class ReferralProgrammeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    private Organization $partner;

    private User $partnerAdmin;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response('')]);
        Notification::fake();
        Mail::fake();

        $this->partner = Organization::create(['name' => 'Partner Systems', 'country' => 'SA', 'vat_number' => '310122393500003', 'status' => 'active']);
        $this->partnerAdmin = User::factory()->create(['email' => 'lina@partner.test']);
        $this->partnerAdmin->organizations()->attach($this->partner->id, ['role' => 'admin', 'status' => 'active']);
        $this->licence($this->partner);

        $this->operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $this->operations->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_the_programme_starts_off(): void
    {
        $code = app(ReferralCodes::class)->for((string) $this->partner->id)->code;

        $this->signUp(['referral_code' => $code])->assertSessionHasErrors('referral_code');
        $this->portal()->get(route('portal.referrals'))->assertOk()->assertSee('not running');
        $this->guest()->get(route('register'))->assertOk()->assertDontSee('name="referral_code"', false);
    }

    public function test_a_referrer_shares_its_link(): void
    {
        $this->enable();

        $response = $this->portal()->get(route('portal.referrals'))->assertOk();
        $code = app(ReferralCodes::class)->for((string) $this->partner->id)->code;
        $response->assertSee($code)->assertSee(route('register', ['ref' => $code]), false)->assertSee('One free month');

        $this->guest()->get(route('register', ['ref' => strtolower($code)]))->assertOk()->assertSee('value="'.$code.'"', false);
    }

    /**
     * The referral keeps the terms of the day it signed up: a later change
     * to the programme is for new referrals only.
     */
    public function test_signing_up_records_the_terms(): void
    {
        $this->enable(['referred_kind' => 'percent_off', 'referred_percent' => 20]);

        $this->signUp(['referral_code' => $this->code()])->assertRedirect();
        $this->enable(['referred_kind' => 'percent_off', 'referred_percent' => 50]);

        $referral = Referral::query()->sole();
        $this->assertSame(ReferralStatus::SignedUp, $referral->status);
        $this->assertSame((string) $this->partner->id, $referral->referrer_org_id);
        $this->assertSame(20, $referral->agreedTerms()->referred->percent);
        $this->assertTrue(AuditLog::query()->where('action', 'referral.signed_up')->exists());
    }

    /**
     * Sign-up refuses a VAT number already registered; a company made
     * another way with the referrer's is refused here.
     */
    public function test_the_same_vat_is_refused(): void
    {
        $this->enable();
        $twin = Organization::create(['name' => 'Partner Twin', 'country' => 'SA', 'vat_number' => '310122393500003']);

        $referral = app(Referrals::class)->recordSignup($this->code(), $twin, null);

        $this->assertSame(ReferralStatus::Refused, $referral->status);
        $this->assertSame('Same VAT or TRN as the referrer.', $referral->refusal_reason);
    }

    public function test_the_same_email_domain_is_refused(): void
    {
        $this->enable();

        $this->signUp(['referral_code' => $this->code(), 'email' => 'owner@partner.test']);
        $this->assertSame(ReferralStatus::Refused, Referral::query()->sole()->status);
    }

    public function test_public_mail_domains_are_not_shared(): void
    {
        $this->enable();
        $this->partnerAdmin->forceFill(['email' => 'lina@gmail.com'])->save();

        $this->signUp(['referral_code' => $this->code(), 'email' => 'owner@gmail.com']);
        $this->assertSame(ReferralStatus::SignedUp, Referral::query()->sole()->status);
    }

    /**
     * Qualified on the first paid invoice, by default: one free month each,
     * the licences moved on, the referral rewarded.
     */
    public function test_the_first_payment_rewards_both(): void
    {
        $this->enable();
        $organization = $this->referredCompany();
        $before = License::query()->where('org_id', $this->partner->id)->sole()->expires_at;

        app(OrganizationReview::class)->approve((string) $organization->id, (string) $this->operations->id, 'starter', now()->addMonth()->toDateString());
        $this->assertSame(ReferralStatus::Approved, Referral::query()->sole()->status);
        $theirs = License::query()->where('org_id', $organization->id)->sole()->expires_at;

        $this->pay($organization);

        $referral = Referral::query()->with('rewards')->sole();
        $this->assertSame(ReferralStatus::Rewarded, $referral->status);
        $this->assertCount(2, $referral->rewards);
        $this->assertTrue($before->copy()->addMonthNoOverflow()->equalTo(License::query()->where('org_id', $this->partner->id)->sole()->expires_at));
        // The payment renewed its plan by a month; the reward added one more.
        $this->assertTrue($theirs->copy()->addMonthsNoOverflow(2)->isSameDay(License::query()->where('org_id', $organization->id)->sole()->expires_at));
    }

    /**
     * A credit lands in the currency the company is billed in, and its next
     * invoice can use it once.
     */
    public function test_a_credit_reaches_the_invoice(): void
    {
        $this->enable(['qualifying_event' => 'approval', 'referrer_kind' => 'credit', 'referrer_credit' => ['SAR' => '150', 'AED' => '150']]);
        $organization = $this->referredCompany();

        app(OrganizationReview::class)->approve((string) $organization->id, (string) $this->operations->id, 'starter', now()->addMonth()->toDateString());

        $credits = app(CreditSource::class);
        $available = $credits->available((string) $this->partner->id, 'SAR');
        $this->assertCount(1, $available);
        $this->assertSame('150.00', $available[0]->amount);
        $this->assertNotNull($available[0]->expiresAt);
        $this->assertSame([], $credits->available((string) $this->partner->id, 'AED'));

        $credits->redeem($available[0]->id, '0199a000-0000-7000-8000-000000000001', '150.00');
        $this->assertSame([], $credits->available((string) $this->partner->id, 'SAR'));

        $this->actingAs($this->operations)->get(route('admin.referrals.index'))->assertOk()->assertSee('150.00');
    }

    /**
     * The currency a credit lands in is the one Finance › Settings bills the
     * company in - its own, here dirhams - so its next invoice can use it.
     */
    public function test_a_credit_follows_the_billing_currency(): void
    {
        $finance = app(FinanceSettings::class);
        $finance->setCurrencies(['SAR', 'AED'], 'SAR', null);
        $finance->setForCustomer((string) $this->partner->id, [FinanceSettings::CURRENCY => 'AED'], null);
        $this->enable(['qualifying_event' => 'approval', 'referrer_kind' => 'credit', 'referrer_credit' => ['SAR' => '150', 'AED' => '140']]);
        $organization = $this->referredCompany();

        app(OrganizationReview::class)->approve((string) $organization->id, (string) $this->operations->id, 'starter', now()->addMonth()->toDateString());

        $credits = app(CreditSource::class);
        $this->assertSame([], $credits->available((string) $this->partner->id, 'SAR'));
        $this->assertSame('140.00', $credits->available((string) $this->partner->id, 'AED')[0]->amount);
    }

    /**
     * A fixed credit comes off before VAT, so 100 lowers a total with 15%
     * VAT by 115: the admin's settings and the customer's page say so, in
     * both languages.
     */
    public function test_credit_wording_says_before_vat(): void
    {
        $this->enable(['referrer_kind' => 'credit', 'referrer_credit' => ['SAR' => '100']]);

        $this->portal()->get(route('portal.referrals'))->assertOk()
            ->assertSee('A fixed credit comes off the invoice before VAT, so it lowers the total by the credit and its VAT: at 15% VAT, a credit of 100.00 lowers the total by 115.00.');
        $this->actingAs($this->operations)->get(route('admin.referrals.settings'))->assertOk()
            ->assertSee('at 15% VAT, a credit of 100.00 lowers the total by 115.00.');

        $this->partnerAdmin->forceFill(['locale' => 'ar'])->save();
        $this->operations->forceFill(['locale' => 'ar'])->save();
        $this->portal()->get(route('portal.referrals'))->assertOk()
            ->assertSee('يُخصم الرصيد الدائن الثابت من الفاتورة قبل ضريبة القيمة المضافة، فينخفض الإجمالي بمقدار الرصيد وضريبته');
        $this->actingAs($this->operations)->get(route('admin.referrals.settings'))->assertOk()
            ->assertSee('يُخصم الرصيد الدائن الثابت من الفاتورة قبل ضريبة القيمة المضافة، فينخفض الإجمالي بمقدار الرصيد وضريبته');
    }

    /**
     * Past the yearly cap a referrer's reward waits for an admin, who can
     * grant it past the cap.
     */
    public function test_the_yearly_cap_holds_a_reward(): void
    {
        $this->enable(['qualifying_event' => 'approval', 'yearly_cap' => 1]);
        $first = $this->referredCompany();
        app(OrganizationReview::class)->approve((string) $first->id, (string) $this->operations->id, 'starter', now()->addMonth()->toDateString());
        $second = $this->referredCompany('second@najd.test', 'Najd Foods', '300000000000103');
        app(OrganizationReview::class)->approve((string) $second->id, (string) $this->operations->id, 'starter', now()->addMonth()->toDateString());

        $held = Referral::query()->where('org_id', $second->id)->sole();
        $this->assertSame(ReferralStatus::Qualified, $held->status);
        $this->assertSame(RewardStatus::Withheld, $held->rewards()->where('side', 'referrer')->sole()->status);

        $this->actingAs($this->operations)->post(route('admin.referrals.approve', $held->id))->assertSessionHas('success');

        $this->assertSame(ReferralStatus::Rewarded, $held->fresh()->status);
    }

    public function test_the_credit_cap_cuts_a_credit(): void
    {
        $this->enable(['qualifying_event' => 'approval', 'referrer_kind' => 'credit', 'referrer_credit' => ['SAR' => '100'], 'credit_cap' => '150']);

        foreach ([['first@najd.test', 'Najd Foods', '300000000000103'], ['second@hijaz.test', 'Hijaz Foods', '300000000000203']] as [$email, $name, $vat]) {
            $organization = $this->referredCompany($email, $name, $vat);
            app(OrganizationReview::class)->approve((string) $organization->id, (string) $this->operations->id, 'starter', now()->addMonth()->toDateString());
        }

        $amounts = AccountCredit::query()->where('org_id', $this->partner->id)->orderBy('created_at')->pluck('amount')->all();
        $this->assertSame(['100.00', '50.00'], $amounts);
    }

    /**
     * Reversed, its credit is taken back and its months come off the plan,
     * each audited with why.
     */
    public function test_an_admin_reverses_a_referral(): void
    {
        $this->enable(['qualifying_event' => 'approval', 'referrer_kind' => 'credit', 'referrer_credit' => ['SAR' => '100']]);
        $organization = $this->referredCompany();
        app(OrganizationReview::class)->approve((string) $organization->id, (string) $this->operations->id, 'starter', now()->addMonth()->toDateString());
        $theirs = License::query()->where('org_id', $organization->id)->sole()->expires_at;
        $referral = Referral::query()->sole();

        $this->actingAs($this->operations)->post(route('admin.referrals.reverse', $referral->id), ['reason' => 'Same owner as the partner'])
            ->assertSessionHas('success');

        $this->assertSame(ReferralStatus::Reversed, $referral->fresh()->status);
        $this->assertSame(AccountCredit::REVERSED, AccountCredit::query()->sole()->status);
        $this->assertTrue($theirs->copy()->subMonthNoOverflow()->isSameDay(License::query()->where('org_id', $organization->id)->sole()->expires_at));
        $this->assertSame(2, AuditLog::query()->where('action', 'referral.reward.reversed')->count());
    }

    public function test_an_admin_adjusts_a_credit(): void
    {
        $this->enable(['qualifying_event' => 'approval', 'referrer_kind' => 'credit', 'referrer_credit' => ['SAR' => '100']]);
        $organization = $this->referredCompany();
        app(OrganizationReview::class)->approve((string) $organization->id, (string) $this->operations->id, 'starter', now()->addMonth()->toDateString());
        $referral = Referral::query()->with('rewards')->sole();
        $reward = $referral->rewards->firstWhere('side', 'referrer');

        $this->actingAs($this->operations)->post(route('admin.referrals.adjust', [$referral->id, $reward->id]), ['value' => '250', 'reason' => 'Agreed with the partner'])
            ->assertSessionHas('success');

        $this->assertSame('250.00', AccountCredit::query()->sole()->amount);
        $entry = AuditLog::query()->where('action', 'referral.reward.adjusted')->sole();
        $this->assertSame('Agreed with the partner', $entry->new_values['reason']);
    }

    /**
     * A partner's own terms are its referrals', the programme's everyone
     * else's - and they keep the programme running for it.
     */
    public function test_a_partner_has_its_own_terms(): void
    {
        $this->actingAs($this->operations)->post(route('admin.referrals.organization.update', $this->partner->id), [
            ...$this->form(), 'enabled' => '1', 'referrer_kind' => 'free_months', 'referrer_months' => '3',
        ])->assertSessionHas('success');

        $this->signUp(['referral_code' => $this->code()])->assertRedirect();

        $this->assertSame(3, Referral::query()->sole()->agreedTerms()->referrer->months);
        $this->guest()->actingAs($this->operations)->get(route('admin.referrals.organization', $this->partner->id))->assertOk()->assertSee('Its own terms');
    }

    public function test_the_settings_save_from_the_console(): void
    {
        $this->actingAs($this->operations)->post(route('admin.referrals.settings.update'), [
            ...$this->form(), 'enabled' => '1', 'referred_kind' => 'credit', 'referred_credit' => ['SAR' => '75', 'AED' => ''],
        ])->assertSessionHas('success');

        $terms = ReferralTerms::fromArray((array) app(Customizations::class)->get(ReferralTerms::KEY, Scope::platform()));
        $this->assertTrue($terms->enabled);
        $this->assertSame(['SAR' => '75.00'], $terms->referred->credit);

        $this->actingAs($this->operations)->post(route('admin.referrals.settings.update'), [...$this->form(), 'referred_kind' => 'credit', 'referred_credit' => []])
            ->assertSessionHasErrors('referred_kind');
    }

    public function test_support_cannot_change_the_terms(): void
    {
        $this->operations->forceFill(['platform_role' => 'support'])->save();

        $this->actingAs($this->operations)->get(route('admin.referrals.settings'))->assertOk();
        $this->actingAs($this->operations)->post(route('admin.referrals.settings.update'), $this->form())->assertForbidden();
    }

    public function test_the_referrer_never_sees_the_name(): void
    {
        $this->enable();
        $this->referredCompany();

        $this->portal()->get(route('portal.referrals'))->assertOk()->assertSee('Signed up')->assertDontSee('Najd Trading');
        $this->actingAs($this->operations)->get(route('admin.referrals.index'))->assertOk()->assertSee('Najd Trading');
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    private function enable(array $terms = []): void
    {
        app(Customizations::class)->set(ReferralTerms::KEY, Level::Platform, Scope::platform(), [...ReferralTerms::DEFAULTS, 'enabled' => true, ...$terms], null);
    }

    /**
     * @return array<string, string>
     */
    private function form(): array
    {
        return [
            'enabled' => '0', 'qualifying_event' => 'first_paid',
            'referrer_kind' => 'free_months', 'referrer_months' => '1',
            'referred_kind' => 'free_months', 'referred_months' => '1',
            'expiry_days' => '365',
        ];
    }

    private function code(): string
    {
        return app(ReferralCodes::class)->for((string) $this->partner->id)->code;
    }

    /**
     * A company signed up with the partner's code, waiting for review.
     */
    private function referredCompany(string $email = 'owner@najd.test', string $name = 'Najd Trading', string $vat = '300000000000003'): Organization
    {
        $this->signUp(['referral_code' => $this->code(), 'email' => $email, 'company_name' => $name, 'vat_number' => $vat])->assertRedirect();
        $this->guest();

        return User::query()->where('email', $email)->sole()->organizations()->sole();
    }

    /**
     * A plan payment the platform invoices, issuing stubbed: the invoice it
     * settles in full is what announces the payment (InvoicePaid).
     */
    private function pay(Organization $organization): void
    {
        $platform = Organization::create([
            'name' => Brand::name('en').' Platform',
            'name_ar' => 'منصة '.Brand::name('ar'),
            'country' => 'SA',
            'vat_number' => '311111111100003',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        config(['billing.organization_id' => $platform->id]);
        $this->mock(InvoiceIssuer::class, fn ($mock) => $mock->shouldReceive('issue')->once()->andReturn(IssueOutcome::issuedOnly()));

        app(Billing::class)->record($organization, LicenseTier::Starter, 1, '500.00', PaymentMethod::BankTransfer, 'TRX-1', CarbonImmutable::now(), null, (string) $this->operations->id);
    }

    private function licence(Organization $organization): void
    {
        License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => $organization->name,
            'contact_email' => 'ops@partner.test',
            'tier' => LicenseTier::Starter,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addMonths(6),
        ]);
    }

    /** Signed out, with a fresh session: who signs up or reads the sign-up form. */
    private function guest(): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function portal(): static
    {
        return $this->actingAs($this->partnerAdmin)->withSession(['portal_org_id' => $this->partner->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function signUp(array $overrides = []): TestResponse
    {
        return $this->guest()->post('/register', array_filter(array_merge([
            'name' => 'Omar Al-Harbi',
            'email' => 'owner@najd.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'company_name' => 'Najd Trading',
            'company_name_ar' => 'شركة نجد',
            'vat_number' => '300000000000003',
            'cr_number' => '1010010000',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'terms' => '1',
        ], $overrides), fn (mixed $value): bool => $value !== null));
    }
}
