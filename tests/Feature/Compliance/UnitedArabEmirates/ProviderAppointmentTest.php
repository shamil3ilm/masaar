<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Mail\ProviderAppointmentReminderMail;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Models\ProviderAppointment;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\ProviderAppointments;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspGatewayResolver;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * A UAE organization's appointment of its accredited service provider: one
 * for sending and receiving (UAE Electronic Invoicing Guidelines v1.1, p14),
 * appointed through EmaraTax (p38-39) by the last date its revenue band sets
 * (p19). Recorded on the company page, shown in its setup and on the admin
 * console, and reminded before the configured date.
 */
class ProviderAppointmentTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private const TOKEN = 'appointment-token';

    private Organization $gulf;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        $this->gulf = $this->uaeOrganization();
        $this->gulf->forceFill(['status' => 'active'])->save();
        $this->admin = $this->member($this->gulf, 'admin');
    }

    public function test_appointment_recorded_on_company_page(): void
    {
        $this->record($this->admin, ['provider' => 'platform', 'status' => 'appointed', 'appointed_at' => '2026-10-01', 'reference' => 'EMT-123'])
            ->assertRedirect(route('portal.company.edit'))->assertSessionHas('success');

        $appointment = ProviderAppointment::withoutTenantScope(fn () => ProviderAppointment::query()->sole());
        $this->assertSame(['platform', 'appointed', 'EMT-123', '2026-10-01', $this->admin->id], [
            $appointment->provider, $appointment->status, $appointment->reference, $appointment->appointed_at?->toDateString(), $appointment->created_by,
        ]);
        $this->assertTrue(app(ProviderAppointments::class)->appointed($this->gulf->id));
        $this->assertSame('platform', app(AspGatewayResolver::class)->providerFor($this->gulf->id));
    }

    /** Another provider ends the appointment in force and starts a new one; the record stays. */
    public function test_new_provider_ends_the_old(): void
    {
        config(['peppol.providers.second' => ['label' => 'Second provider']]);
        $this->record($this->admin, ['provider' => 'platform', 'status' => 'appointed', 'appointed_at' => '2026-09-01']);
        $this->record($this->admin, ['provider' => 'second', 'status' => 'pending']);

        $rows = ProviderAppointment::withoutTenantScope(fn () => ProviderAppointment::query()->orderBy('created_at')->get());
        $this->assertSame([['platform', 'ended'], ['second', 'pending']], $rows->map(fn ($row): array => [$row->provider, $row->status])->all());
        $this->assertSame('second', app(AspGatewayResolver::class)->providerFor($this->gulf->id));

        $this->expectException(RuntimeException::class);
        $rows[0]->delete();
    }

    public function test_only_its_administrators_record_it(): void
    {
        $this->record($this->member($this->gulf, 'member'), ['provider' => 'platform', 'status' => 'pending'])->assertForbidden();
        $this->record($this->admin, ['provider' => 'a vendor', 'status' => 'appointed'])->assertSessionHasErrors(['provider', 'appointed_at']);

        $this->assertSame(0, ProviderAppointment::withoutTenantScope(fn () => ProviderAppointment::query()->count()));
    }

    /** Readiness: a setup step, on its dashboard and on the admin console's organization page. */
    public function test_appointment_is_a_setup_step(): void
    {
        config(['peppol.countries.AE.appointment.deadline' => '2027-03-31']);
        $step = fn (): array => collect(app(SetupChecklist::class)->steps($this->gulf->fresh()))->firstWhere('key', 'uae_service_provider');

        $this->assertFalse($step()['done']);
        $this->assertStringContainsString('2027-03-31', $step()['detail']);

        $this->actingAs(User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']))
            ->get("/admin/organizations/{$this->gulf->id}")
            ->assertOk()
            ->assertSee('data-setup-step="uae_service_provider"', false)
            ->assertSee('Appoint your e-invoicing service provider');

        $this->record($this->admin, ['provider' => 'platform', 'status' => 'appointed', 'appointed_at' => '2026-10-01']);
        $this->assertTrue($step()['done']);
    }

    /** Reminded on each configured day before the last date, until a provider is appointed. */
    public function test_reminders_before_the_deadline(): void
    {
        Mail::fake();
        config(['peppol.countries.AE.appointment.deadline' => now()->addDays(14)->toDateString()]);

        $this->artisan('uae:remind-provider-appointments')->expectsOutputToContain('Sent 1')->assertSuccessful();
        Mail::assertSent(ProviderAppointmentReminderMail::class, fn (ProviderAppointmentReminderMail $mail): bool => $mail->hasTo($this->admin->email) && $mail->days === 14);

        $this->travel(1)->days();
        $this->artisan('uae:remind-provider-appointments')->expectsOutputToContain('Sent 0')->assertSuccessful();
    }

    public function test_no_reminder_once_appointed(): void
    {
        Mail::fake();
        config(['peppol.countries.AE.appointment.deadline' => now()->addDays(7)->toDateString()]);
        $this->record($this->admin, ['provider' => 'platform', 'status' => 'appointed', 'appointed_at' => now()->toDateString()]);

        $this->artisan('uae:remind-provider-appointments')->assertSuccessful();

        Mail::assertNothingSent();
    }

    /** No date configured, no reminders; an organization's own date wins over the platform's. */
    public function test_deadline_is_configuration_not_code(): void
    {
        Mail::fake();
        config(['peppol.countries.AE.appointment.deadline' => null]);
        $this->artisan('uae:remind-provider-appointments')->assertSuccessful();
        Mail::assertNothingSent();

        $this->record($this->admin, ['provider' => 'platform', 'status' => 'pending', 'deadline_on' => now()->addDays(30)->toDateString()]);
        $this->artisan('uae:remind-provider-appointments')->assertSuccessful();
        Mail::assertSent(ProviderAppointmentReminderMail::class, 1);
    }

    /** @param  array<string, mixed>  $data */
    private function record(User $user, array $data): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['portal_org_id' => $this->gulf->id, '_token' => self::TOKEN])
            ->from(route('portal.company.edit'))
            ->post(route('portal.company.service-provider'), [...$data, '_token' => self::TOKEN]));
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
