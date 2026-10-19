<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\DTOs\ActiveTheme;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Services\BrandAssets;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\CertificateExpiringMail;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The certificate-expiry email, in the organization's branding and to its
 * administrators only.
 */
class BrandedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_warning_wears_the_organizations_brand(): void
    {
        Storage::fake(BrandAssets::DISK);
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        Mail::fake();
        Event::fake();

        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003']);

        app(ThemeService::class)->save(
            $acme->id,
            ThemeDefinition::fromArray(['name' => 'Acme Books', 'colors' => ['primary' => '#7C3AED']]),
            ['logo' => UploadedFile::fake()->image('logo.png', 120, 40)],
        );

        $admin = $this->member($acme, 'admin', 'active');
        $this->member($acme, 'member', 'active');
        $removed = $this->member($acme, 'admin', 'removed');

        app(CredentialStore::class)->put($acme->id, null, CredentialStore::PCSID, ['pcsid' => 'CERT', 'secret' => 's', 'privateKey' => 'k']);
        $this->mock(CertificateService::class)
            ->shouldReceive('getExpiryDate')
            ->andReturn(now()->addDays(7)->addHours(2)->toDateTimeImmutable());

        $this->artisan('fatoora:check-certificate', ['--notify' => true])->assertSuccessful();

        Mail::assertSent(CertificateExpiringMail::class, function (CertificateExpiringMail $mail) use ($admin, $removed, $acme): bool {
            $html = $mail->render();

            return $mail->hasTo($admin->email)
                && ! $mail->hasTo($removed->email)
                && $mail->daysRemaining === 7
                && str_contains($html, 'Acme Books')
                && str_contains($html, '#7C3AED')
                && str_contains($html, "/theme/{$acme->id}/logo");
        });
        Mail::assertSentCount(1);
    }

    /**
     * Mail clients do not draw SVG, so an SVG logo gives way to the name; and
     * the text part is plain text, not HTML.
     */
    public function test_the_mail_suits_mail_clients(): void
    {
        $theme = new ActiveTheme(
            scope: 'platform',
            definition: ThemeDefinition::shipped(),
            stylesheetUrl: 'https://example.com/theme/platform/stylesheet',
            faviconUrl: 'https://example.com/favicon.svg',
            faviconType: 'image/svg+xml',
            logoUrl: 'https://example.com/theme/platform/logo',
            logoType: 'image/svg+xml',
        );

        $mail = new CertificateExpiringMail('A & B Trading', 7, '2026-10-22', $theme);

        $this->assertStringNotContainsString('/theme/platform/logo', $mail->render());
        $this->assertStringContainsString('A & B Trading', view('countries.saudi-arabia.emails.certificate-expiring-text', [
            'brand' => $theme, 'organizationName' => 'A & B Trading', 'daysRemaining' => 7, 'expiryDate' => '2026-10-22',
        ])->render());
    }

    private function member(Organization $organization, string $role, string $status): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => $status]);

        return $user;
    }
}
