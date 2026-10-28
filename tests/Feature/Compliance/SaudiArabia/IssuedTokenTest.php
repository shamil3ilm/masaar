<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationCheck;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A device signs with the certificate ZATCA issued, in the form it issues it.
 *
 * ZATCA returns a production certificate as binarySecurityToken: the
 * certificate's base64 DER, base64-encoded again. API onboarding stores that
 * token as it comes, and the signer read the stored value as a PEM - keeping
 * every line that was not a header, which for a token is the whole token. So
 * the "certificate" it signed under was the token itself: unparseable, so in
 * production the key-match check refused it and no invoice could be issued;
 * and anywhere it was not refused, the document embedded the token where the
 * certificate belongs.
 *
 * Every fixture stored a PEM, so no test had ever handed the signer what the
 * authority actually returns. The token is still what authenticates, as it
 * came: ZATCA's Basic auth takes the token, not the certificate.
 */
class IssuedTokenTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    public function test_a_device_signs_with_its_token(): void
    {
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'fatoora.environment' => 'production']);

        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);
        // A self-signed device names no revocation list, which production
        // refuses to stamp with (RevocationCheckTest); the token is what is
        // under test here.
        $this->partialMock(RevocationCheck::class, fn ($mock) => $mock->shouldReceive('assertMayStamp'));

        $acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $keys = $this->selfSignedCredentials();
        $body = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $keys['certificate']);
        $token = base64_encode($body);

        // Stored the way OnboardingController stores CsidOnboarding's result.
        app(CredentialStore::class)->put($acme->id, null, CredentialStore::PCSID, [
            'pcsid' => $token,
            'secret' => 'device-secret',
            'privateKey' => $keys['privateKey'],
        ]);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $acme->id,
            'invoice_number' => 'ACME-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $acme->fresh());

        preg_match('/<ds:X509Certificate>([^<]+)<\/ds:X509Certificate>/', (string) $invoice->fresh()->signed_xml, $embedded);

        $this->assertSame($body, preg_replace('/\s+/', '', $embedded[1] ?? ''), 'the signature does not carry the certificate');

        app(SubmissionTracker::class)->submit($invoice->fresh(['lines']));

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization')
            === ['Basic '.base64_encode($token.':device-secret')]);
    }
}
