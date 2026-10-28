<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\CertificateExpiringMail;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificatePem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use App\Domains\Webhook\Events\CertificateExpiring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every till signs with a certificate of its own (E-Invoicing Detailed
 * Guidelines p10, p39: a CSID per EGS unit), so each one's expiry is checked
 * and each is warned about on its own: head office's, a branch's first till
 * and any added since. A retired till files nothing again and is left out.
 */
class TillCertificateExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $admin;

    private EgsUnit $second;

    private EgsUnit $retired;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        Mail::fake();
        Event::fake([CertificateExpiring::class]);

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $olaya = Branch::acrossTenants()->create(['org_id' => $this->acme->id, 'name' => 'Olaya', 'device_serial' => 'EGS-OLAYA',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $this->second = app(Tills::class)->add($olaya, 'Counter 2');
        $this->retired = app(Tills::class)->add($olaya, 'Old counter');
        $this->retired->forceFill(['status' => EgsUnit::RETIRED, 'retired_at' => now()])->save();
        // A till not yet onboarded holds no certificate, and is not checked.
        app(Tills::class)->add($olaya, 'New counter');

        $store = app(CredentialStore::class);
        foreach (['HQ' => null, 'OLAYA' => $olaya->id, 'SECOND' => $this->second->id, 'RETIRED' => $this->retired->id] as $name => $unit) {
            $store->put($this->acme->id, $unit, CredentialStore::PCSID, ['pcsid' => 'CERT-'.$name, 'secret' => 's', 'privateKey' => 'k']);
        }

        // Head office and the first till in 7 days, the second already expired.
        $this->mock(CertificateService::class)->shouldReceive('getExpiryDate')->andReturnUsing(
            fn (string $certificate) => $certificate === CertificatePem::from('CERT-SECOND')
                ? now()->subDay()->toDateTimeImmutable()
                : now()->addDays(7)->addHours(2)->toDateTimeImmutable()
        );
    }

    public function test_every_active_till_is_checked(): void
    {
        $this->artisan('fatoora:check-certificate')
            ->expectsOutputToContain('Head office')
            ->expectsOutputToContain('Till 1 · Olaya')
            ->expectsOutputToContain('Counter 2')
            ->doesntExpectOutputToContain('Old counter')
            ->doesntExpectOutputToContain('New counter')
            ->assertSuccessful();
    }

    public function test_admins_are_mailed_per_till(): void
    {
        $this->artisan('fatoora:check-certificate', ['--notify' => true])->assertSuccessful();

        $sent = [];
        Mail::assertSent(CertificateExpiringMail::class, function (CertificateExpiringMail $mail) use (&$sent): bool {
            $sent[] = [$mail->tillName, $mail->daysRemaining];

            return $mail->hasTo($this->admin->email);
        });
        sort($sent);

        $this->assertSame([['Counter 2 · Olaya', -1], ['Head office', 7], ['Till 1 · Olaya', 7]], $sent);
        $this->assertStringContainsString('Counter 2', Mail::sent(CertificateExpiringMail::class, fn ($mail) => $mail->tillName === 'Counter 2 · Olaya')->first()->render());
        Event::assertDispatchedTimes(CertificateExpiring::class, 3);
        Event::assertDispatched(CertificateExpiring::class, fn (CertificateExpiring $event): bool => $event->deviceId === (string) $this->second->id);
    }

    /** Once a day each: a second run the same day sends nothing more. */
    public function test_each_till_is_warned_once_daily(): void
    {
        $this->artisan('fatoora:check-certificate', ['--notify' => true])->assertSuccessful();
        $this->artisan('fatoora:check-certificate', ['--notify' => true])->assertSuccessful();

        Mail::assertSentCount(3);
    }
}
