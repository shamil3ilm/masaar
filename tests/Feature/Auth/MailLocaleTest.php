<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Auth\Mail\EmailChangeMail;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Mail\RenewalReminderMail;
use App\Domains\Billing\Services\RenewalReminders;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mail is written in the language its reader chose for the portal.
 */
class MailLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_follows_the_account(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['locale' => 'ar']);

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, fn ($notification, $channels, $notifiable, $locale): bool => $locale === 'ar');
    }

    /**
     * The new address has no account yet; it hears in the owner's language.
     */
    public function test_a_new_address_hears_in_arabic(): void
    {
        Mail::fake();
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)->post('/account/email', ['email' => 'sara@new.test', 'password' => 'password']);

        Mail::assertSent(EmailChangeMail::class, fn (EmailChangeMail $mail): bool => $mail->hasTo('sara@new.test') && $mail->locale === 'ar');
    }

    /**
     * One reminder per admin, each in their own language.
     */
    public function test_each_admin_reads_their_language(): void
    {
        Mail::fake();
        $acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        License::createWithCredentials([
            'org_id' => $acme->id,
            'organization_name' => 'Acme Trading',
            'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Starter,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addDays(14)->setTime(12, 0),
        ]);
        $arabic = User::factory()->create(['email' => 'ar@acme.test', 'locale' => 'ar']);
        $english = User::factory()->create(['email' => 'en@acme.test']);
        $arabic->organizations()->attach($acme->id, ['role' => 'admin', 'status' => 'active']);
        $english->organizations()->attach($acme->id, ['role' => 'admin', 'status' => 'active']);

        app(RenewalReminders::class)->send();

        Mail::assertSent(RenewalReminderMail::class, fn (RenewalReminderMail $mail): bool => $mail->hasTo('ar@acme.test') && $mail->locale === 'ar');
        Mail::assertSent(RenewalReminderMail::class, fn (RenewalReminderMail $mail): bool => $mail->hasTo('en@acme.test') && $mail->locale === null);
    }

    public function test_the_mail_renders_in_arabic(): void
    {
        $html = (new EmailChangeMail(name: 'Sara', confirmUrl: 'https://masaar.test/x', validHours: 24))->locale('ar')->render();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $html);
    }
}
