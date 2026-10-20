<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Mail\EinvoicingStartReminderMail;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Models\ProviderAppointment;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An organization whose e-invoicing start date is near and that has no
 * service provider appointed is reminded - by email to its administrators
 * and on its dashboard - 90, 30 and 7 days before, once each (UAE
 * Electronic Invoicing Guidelines v1.1, 6.1, 8.3).
 */
class EinvoicingStartReminderTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['peppol.countries.AE.enabled' => true, 'peppol.countries.AE.appointment.deadline' => null]);
        $this->gulf = $this->uaeOrganization(['einvoicing_start' => null], appointed: false);
        $this->gulf->forceFill(['status' => 'active'])->save();
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->gulf->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_each_threshold_is_sent_once(): void
    {
        $this->startIn(90);

        $this->remind();
        $this->remind();
        $this->travel(60)->days();
        $this->remind();
        $this->travel(23)->days();
        $this->remind();
        $this->travel(7)->days();
        $this->remind();

        $this->assertSame([90, 30, 7], $this->sentDays());
        Mail::assertSent(EinvoicingStartReminderMail::class, fn (EinvoicingStartReminderMail $mail): bool => $mail->hasTo($this->admin->email));
    }

    /** Set late, it reminds at the nearest threshold, not each one it missed. */
    public function test_late_date_sends_nearest_only(): void
    {
        $this->startIn(20);

        $this->remind();

        $this->assertSame([30], $this->sentDays());
    }

    public function test_no_reminder_once_appointed(): void
    {
        $this->startIn(30);
        ProviderAppointment::withoutTenantScope(fn () => ProviderAppointment::query()->create([
            'org_id' => $this->gulf->id, 'provider' => 'platform', 'status' => ProviderAppointment::APPOINTED, 'appointed_at' => now(),
        ]));

        $this->remind();

        Mail::assertNothingSent();
    }

    public function test_no_date_no_reminder(): void
    {
        $this->remind();

        Mail::assertNothingSent();
    }

    /** A new date is a new start: its thresholds are reminded again. */
    public function test_new_date_reminds_again(): void
    {
        $this->startIn(30);
        $this->remind();
        $this->startIn(7);
        $this->remind();

        $this->assertSame([30, 7], $this->sentDays());
    }

    public function test_dashboard_shows_the_notice(): void
    {
        $this->startIn(30);

        $this->asRequest(fn (): TestResponse => $this->actingAs($this->admin)
            ->withSession(['portal_org_id' => $this->gulf->id])
            ->get('/portal'))
            ->assertOk()
            ->assertSee('data-einvoicing-reminder', false)
            ->assertSee('Before e-invoicing');
    }

    private function startIn(int $days): void
    {
        app(EinvoicingStart::class)->set($this->gulf, now(EinvoicingStart::TIMEZONE)->addDays($days)->toDateString(), null);
    }

    private function remind(): void
    {
        $this->artisan('uae:remind-provider-appointments')->assertSuccessful();
    }

    /** @return list<int> */
    private function sentDays(): array
    {
        return Mail::sent(EinvoicingStartReminderMail::class)->map(fn (EinvoicingStartReminderMail $mail): int => $mail->days)->values()->all();
    }
}
