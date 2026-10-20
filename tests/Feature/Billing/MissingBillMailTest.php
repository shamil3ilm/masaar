<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\Mail\MissingBillsMail;
use App\Domains\Billing\Models\RecurringExpense;
use App\Domains\Billing\Services\RecurringExpenses;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A recurring cost's bill that becomes missing is emailed once, queued, to
 * the admins who hold the finance permission, each in their own language -
 * unless the platform or the admin turned it off.
 */
class MissingBillMailTest extends TestCase
{
    use RefreshDatabase;

    private User $operations;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'));
        $this->operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations', 'locale' => 'ar']);
        $this->support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        User::factory()->create();

        $supplier = app(Suppliers::class)->save(null, ['name' => 'E-invoicing Provider LLC', 'country' => 'SA', 'currency' => 'SAR', 'payment_terms_days' => 15, 'category' => 'e_invoicing_provider'], null);
        app(RecurringExpenses::class)->save(null, [
            'supplier_id' => $supplier->id, 'description' => 'Monthly provider fee', 'category' => 'e_invoicing_provider', 'currency' => 'SAR',
            'net' => '1000', 'vat' => '150', 'vat_treatment' => 'recoverable', 'frequency' => RecurringExpense::MONTHLY, 'next_due_on' => '2026-10-05', 'active' => true,
        ], null);
    }

    public function test_a_missing_bill_is_emailed_once(): void
    {
        $service = app(RecurringExpenses::class);
        $service->run(CarbonImmutable::parse('2026-10-05'));
        Mail::assertNothingQueued();

        $service->run(CarbonImmutable::parse('2026-10-12'));
        $service->run(CarbonImmutable::parse('2026-10-13'));

        Mail::assertQueued(MissingBillsMail::class, 1);
        Mail::assertQueued(MissingBillsMail::class, fn (MissingBillsMail $mail): bool => $mail->hasTo($this->operations->email)
            && $mail->locale === 'ar' && $mail->bills[0]['expected_on'] === '2026-10-05');
        Mail::assertNotQueued(MissingBillsMail::class, fn (MissingBillsMail $mail): bool => $mail->hasTo($this->support->email));
    }

    public function test_the_mail_names_the_brand(): void
    {
        $mail = new MissingBillsMail([['supplier' => 'Provider', 'description' => 'Fee', 'expected_on' => '2026-10-05', 'amount' => '1150.00 SAR', 'url' => 'https://example.test/x']], 'https://example.test');

        $mail->assertSeeInHtml('Provider');
        $this->assertStringContainsString(Brand::name('en'), $mail->envelope()->subject);
    }

    public function test_platform_or_admin_can_opt_out(): void
    {
        app(FinanceSettings::class)->set(FinanceSettings::MISSING_BILL_EMAILS, false, null);
        $service = app(RecurringExpenses::class);
        $service->run(CarbonImmutable::parse('2026-10-05'));
        $service->run(CarbonImmutable::parse('2026-10-12'));
        Mail::assertNothingQueued();

        // On for the platform, off for this admin alone.
        app(FinanceSettings::class)->set(FinanceSettings::MISSING_BILL_EMAILS, true, null);
        app(Customizations::class)->set(FinanceSettings::MISSING_BILL_EMAILS, Level::User, Scope::person((string) $this->operations->id), false, null);
        $this->assertFalse(app(FinanceSettings::class)->missingBillEmails((string) $this->operations->id));
        $this->assertTrue(app(FinanceSettings::class)->missingBillEmails((string) $this->support->id));
    }
}
