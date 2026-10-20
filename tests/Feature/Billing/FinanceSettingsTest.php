<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Billing\Settings\Iban;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin › Finance › Settings: what the platform bills its customers by,
 * each value a customization with the platform's default and - where it is
 * agreed per customer - the customer's own over it.
 */
class FinanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** The SWIFT IBAN Registry's own examples. */
    private const SAUDI_IBAN = 'SA03 8000 0000 6080 1016 7519';

    private const EMIRATI_IBAN = 'AE07 0331 2345 6789 0123 456';

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operations = $this->admin('operations');
    }

    /** At launch: riyals on, dirhams ready and off, one free month. */
    public function test_the_shipped_defaults_hold(): void
    {
        $settings = app(FinanceSettings::class);

        $this->assertSame(['SAR'], $settings->enabledCurrencies());
        $this->assertSame('SAR', $settings->currencyFor());
        $this->assertSame(1, $settings->freeMonths());
        $this->assertSame(30, $settings->paymentTermsDays());
        $this->assertSame([1, 7, 14], $settings->remindersAfter());
        $this->assertTrue($settings->methodEnabled(PaymentMethod::BankTransfer));
        $this->assertTrue($settings->methodEnabled(PaymentMethod::Card));
        $this->assertSame(FinanceSettings::RATE_MANUAL, $settings->rateSource());
        $this->assertSame('1.00', $settings->tolerance());
    }

    /** Every admin reads it; only the billing permission changes it. */
    public function test_support_reads_but_cannot_change(): void
    {
        $support = $this->admin('support');

        $this->actingAs($support)->get(route('admin.finance.settings'))
            ->assertOk()
            ->assertSee('Finance settings')
            ->assertDontSee('Save currencies');

        $this->actingAs($support)->post(route('admin.finance.settings.currencies'), ['currencies' => ['SAR', 'AED'], 'default' => 'SAR'])
            ->assertForbidden();

        $this->assertSame(['SAR'], app(FinanceSettings::class)->enabledCurrencies());
    }

    public function test_switching_dirhams_on_is_audited(): void
    {
        $this->actingAs($this->operations)
            ->post(route('admin.finance.settings.currencies'), ['currencies' => ['SAR', 'AED'], 'default' => 'SAR'])
            ->assertRedirect(route('admin.finance.settings'));

        $this->assertSame(['SAR', 'AED'], app(FinanceSettings::class)->enabledCurrencies());

        $log = AuditLog::query()->where('action', 'customization.changed')->get()
            ->first(fn (AuditLog $log): bool => ($log->metadata['key'] ?? null) === FinanceSettings::CURRENCIES);
        $this->assertNotNull($log, 'The change was not recorded.');
        $this->assertSame(['SAR' => true, 'AED' => true], $log->new_values['value']);
        $this->assertSame((string) $this->operations->id, (string) $log->user_id);
    }

    public function test_the_default_must_be_on(): void
    {
        $this->actingAs($this->operations)
            ->post(route('admin.finance.settings.currencies'), ['currencies' => ['SAR'], 'default' => 'AED'])
            ->assertSessionHasErrors('default');

        $this->assertSame('SAR', app(FinanceSettings::class)->currencyFor());
    }

    public function test_an_invalid_iban_is_refused(): void
    {
        $this->actingAs($this->operations)
            ->post(route('admin.finance.settings.bank-accounts.store'), $this->account(['iban' => 'SA03 8000 0000 6080 1016 7518']))
            ->assertSessionHasErrors('iban');

        $this->assertSame([], app(FinanceSettings::class)->bankAccounts());
    }

    /** An account per currency, the IBAN kept without its spaces. */
    public function test_accounts_are_kept_per_currency(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.settings.bank-accounts.store'), $this->account());
        $this->actingAs($this->operations)->post(route('admin.finance.settings.bank-accounts.store'), $this->account([
            'currency' => 'AED', 'bank_name' => 'Emirates Bank', 'iban' => self::EMIRATI_IBAN,
        ]));

        $settings = app(FinanceSettings::class);
        $this->assertCount(2, $settings->bankAccounts());
        $this->assertSame('SA0380000000608010167519', $settings->bankAccounts('SAR')[0]['iban']);
        $this->assertSame('Emirates Bank', $settings->bankAccounts('AED')[0]['bank_name']);

        $this->actingAs($this->operations)
            ->post(route('admin.finance.settings.bank-accounts.remove', $settings->bankAccounts('SAR')[0]['key']))
            ->assertRedirect();

        $this->assertSame([], $settings->bankAccounts('SAR'));
        $this->assertCount(1, $settings->bankAccounts('AED'));
    }

    public function test_the_same_account_is_listed_once(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.settings.bank-accounts.store'), $this->account());
        $this->actingAs($this->operations)->post(route('admin.finance.settings.bank-accounts.store'), $this->account(['iban' => 'sa0380000000608010167519']))
            ->assertSessionHasErrors('iban');

        $this->assertCount(1, app(FinanceSettings::class)->bankAccounts());
    }

    /** "14, 1, 7" is the days 1, 7 and 14; one bad entry saves nothing. */
    public function test_reminder_days_are_a_list(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.settings.terms'), [
            'free_months' => 2, 'payment_terms_days' => 14, 'reminder_days_before' => '', 'reminder_days_after' => '14, 1, 7',
        ])->assertSessionHasNoErrors();

        $settings = app(FinanceSettings::class);
        $this->assertSame([1, 7, 14], $settings->remindersAfter());
        $this->assertSame([], $settings->remindersBefore());
        $this->assertSame(2, $settings->freeMonths());

        $this->actingAs($this->operations)->post(route('admin.finance.settings.terms'), [
            'free_months' => 3, 'payment_terms_days' => 14, 'reminder_days_before' => 'soon', 'reminder_days_after' => '1',
        ])->assertSessionHasErrors();

        $this->assertSame(2, $settings->freeMonths(), 'A refused form saved part of itself.');
    }

    /** A customer's own terms, over the platform's, until reset. */
    public function test_a_customer_keeps_its_own_terms(): void
    {
        $customer = Organization::create(['name' => 'Gulf Trading', 'country' => 'SA']);
        $settings = app(FinanceSettings::class);

        $settings->set(FinanceSettings::PAYMENT_TERMS, 45, (string) $this->operations->id, (string) $customer->id);

        $this->assertSame(45, $settings->paymentTermsDays((string) $customer->id));
        $this->assertSame(30, $settings->paymentTermsDays());
        $this->assertSame(45, $settings->ownValues((string) $customer->id)[FinanceSettings::PAYMENT_TERMS]);

        $settings->reset(FinanceSettings::PAYMENT_TERMS, (string) $customer->id, (string) $this->operations->id);

        $this->assertSame(30, $settings->paymentTermsDays((string) $customer->id));
    }

    public function test_a_switched_off_currency_is_refused(): void
    {
        $customer = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        $this->expectExceptionMessage('That currency is switched off');

        app(FinanceSettings::class)->set(FinanceSettings::CURRENCY, 'AED', (string) $this->operations->id, (string) $customer->id);
    }

    /** The day's rate, or the latest before it; a correction is in place. */
    public function test_the_rate_table_answers_by_day(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.settings.rates.store'), ['currency' => 'AED', 'rate_date' => '2026-10-01', 'rate' => '1.02'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->operations)->post(route('admin.finance.settings.rates.store'), ['currency' => 'AED', 'rate_date' => '2026-10-01', 'rate' => '1.021'])
            ->assertSessionHasNoErrors();

        $rates = app(ExchangeRates::class);
        $this->assertSame(['rate' => '1.021000', 'date' => '2026-10-01'], $rates->on('AED', CarbonImmutable::parse('2026-10-05')));
        $this->assertSame(['rate' => '1.021000', 'date' => '2026-10-01'], $rates->on('AED', CarbonImmutable::parse('2026-10-01')));
        $this->assertNull($rates->on('AED', CarbonImmutable::parse('2026-09-30')));
        $this->assertCount(1, $rates->recent());
    }

    public function test_no_rate_for_the_tax_currency(): void
    {
        $this->actingAs($this->operations)
            ->post(route('admin.finance.settings.rates.store'), ['currency' => 'SAR', 'rate_date' => '2026-10-01', 'rate' => '1'])
            ->assertSessionHasErrors('currency');
    }

    public function test_ibans_are_checked_by_mod_97(): void
    {
        $this->assertTrue(Iban::valid(self::SAUDI_IBAN));
        $this->assertTrue(Iban::valid(self::EMIRATI_IBAN));
        $this->assertTrue(Iban::valid('GB82 WEST 1234 5698 7654 32'));
        // A digit off, a digit short, letters where digits go.
        $this->assertFalse(Iban::valid('SA03 8000 0000 6080 1016 7518'));
        $this->assertFalse(Iban::valid('SA03 8000 0000 6080 1016 751'));
        $this->assertFalse(Iban::valid('not an iban'));
        $this->assertSame('SA03 8000 0000 6080 1016 7519', Iban::format('sa0380000000608010167519'));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function account(array $overrides = []): array
    {
        return array_merge([
            'currency' => 'SAR',
            'bank_name' => 'Riyadh Bank',
            'account_name' => 'Platform Operator LLC',
            'iban' => self::SAUDI_IBAN,
            'swift' => 'RIBLSARI',
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
