<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Services\BrandAssets;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Fatoora\Mail\CertificateExpiringMail;
use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
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

    private function member(Organization $organization, string $role, string $status): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => $status]);

        return $user;
    }
}
