<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Mail\AllowanceWarningMail;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A customer's allowances - invoices a month and API calls a day - are its
 * own, set by a platform admin, and the platform's default is unlimited.
 * A set allowance warns the customer's administrators at 80% and 100%, once
 * each a period; an unlimited one never warns and never blocks. The
 * per-minute call cap is a safety limit and stays the tier's.
 */
class CustomerAllowancesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_a_new_licence_is_unlimited(): void
    {
        foreach (LicenseTier::cases() as $tier) {
            $licence = $this->licence($tier);

            $this->assertTrue($licence->isUnlimited('invoices_per_month'), $tier->value);
            $this->assertTrue($licence->isUnlimited('calls_per_day'), $tier->value);
            $this->assertSame($tier->getDefaults()['calls_per_min'], (int) $licence->calls_per_min, 'the safety cap stays');
        }
    }

    public function test_unlimited_never_warns_or_blocks(): void
    {
        $licence = $this->licence(LicenseTier::Starter);
        $metering = app(UsageMeteringService::class);

        foreach (range(1, 150) as $ignored) {
            $metering->reserveInvoiceQuota($licence);
        }

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('allowance_warnings')->count());
    }

    public function test_a_set_allowance_warns_then_refuses(): void
    {
        $licence = $this->licence(LicenseTier::Starter);
        app(LicenseManagementService::class)->updateLimits($licence->id, ['invoices_per_month' => 5]);
        $licence = $licence->fresh();
        $metering = app(UsageMeteringService::class);

        foreach (range(1, 3) as $ignored) {
            $metering->reserveInvoiceQuota($licence);
        }
        Mail::assertNothingSent();

        $metering->reserveInvoiceQuota($licence);
        Mail::assertSent(AllowanceWarningMail::class, fn (AllowanceWarningMail $mail): bool => $mail->percent === 80 && $mail->used === 4);

        $metering->reserveInvoiceQuota($licence);
        Mail::assertSent(AllowanceWarningMail::class, fn (AllowanceWarningMail $mail): bool => $mail->percent === 100);
        Mail::assertSent(AllowanceWarningMail::class, 2);

        $this->expectException(LicenseException::class);
        $metering->reserveInvoiceQuota($licence);
    }

    public function test_api_calls_warn_once_a_day(): void
    {
        $licence = $this->licence(LicenseTier::Unlimited);
        app(LicenseManagementService::class)->updateLimits($licence->id, ['calls_per_day' => 10]);
        $licence = $licence->fresh();
        $metering = app(UsageMeteringService::class);

        foreach (range(1, 9) as $ignored) {
            $metering->checkRateLimit($licence);
        }

        Mail::assertSent(AllowanceWarningMail::class, 1);
        Mail::assertSent(AllowanceWarningMail::class, fn (AllowanceWarningMail $mail): bool => $mail->allowance === 'calls_per_day' && $mail->percent === 80);
    }

    public function test_an_admin_sets_the_allowances(): void
    {
        $licence = $this->licence(LicenseTier::Professional);
        $operator = User::factory()->platformAdmin()->create();

        $this->actingAs($operator)->post(route('admin.licences.allowances', $licence->id), ['invoices_per_month' => '2500', 'calls_per_day' => ''])
            ->assertRedirect(route('admin.licences.show', $licence->id));

        $licence = $licence->fresh();
        $this->assertSame(2500, (int) $licence->invoices_per_month);
        $this->assertTrue($licence->isUnlimited('calls_per_day'));

        $this->actingAs($operator)->post(route('admin.licences.allowances', $licence->id), ['invoices_per_month' => '', 'calls_per_day' => ''])
            ->assertRedirect();
        $this->assertTrue($licence->fresh()->isUnlimited('invoices_per_month'));
    }

    /** A tier change keeps the allowances the customer was given. */
    public function test_a_tier_change_keeps_them(): void
    {
        $licence = $this->licence(LicenseTier::Starter);
        app(LicenseManagementService::class)->updateLimits($licence->id, ['invoices_per_month' => 40]);

        $licence->fresh()->upgradeTier(LicenseTier::Enterprise);

        $this->assertSame(40, (int) $licence->fresh()->invoices_per_month);
        $this->assertSame(120, (int) $licence->fresh()->calls_per_min);
    }

    private function licence(LicenseTier $tier): License
    {
        return app(LicenseManagementService::class)->createLicense([
            'org_id' => $this->acme->id,
            'organization_name' => 'Acme',
            'contact_email' => 'ops@acme.test',
            'tier' => $tier->value,
            'status' => 'active',
        ])['license'];
    }
}
