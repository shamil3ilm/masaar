<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\UnitOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\InvoiceHasher;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\XadesVerifier;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The six compliance samples reach ZATCA signed with the device's own
 * compliance certificate, with a QR, and chained from the initial hash.
 *
 * BR-KSA-27 (a QR on every document), BR-KSA-28 to 30 (the stamp) and
 * BR-KSA-60 (the stamp on simplified documents). The portal onboarding sent
 * them with an empty signature placeholder and no QR.
 */
class SignedComplianceSamplesTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    /** @var array{privateKey: string, certificate: string} */
    private array $device;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '399999999900003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $this->device = $this->selfSignedCredentials(serial: 7);

        // As the compliance endpoint answers: a standard sample cleared, a
        // simplified one reported.
        Http::fake(['*/compliance/invoices' => fn (Request $request) => Http::response(
            str_contains(base64_decode((string) $request['invoice']), 'name="0100000"')
                ? ['validationResults' => ['status' => 'PASS'], 'clearanceStatus' => 'CLEARED']
                : ['validationResults' => ['status' => 'PASS'], 'reportingStatus' => 'REPORTED']
        )]);
    }

    public function test_organization_samples_are_signed_and_chained(): void
    {
        $this->storeCcsid(null);

        $result = app(OrganizationOnboarding::class)->runComplianceCheck($this->acme);

        $this->assertTrue($result['passed']);
        $this->assertSubmittedSigned();
    }

    public function test_till_samples_are_signed_and_chained(): void
    {
        $branch = Branch::acrossTenants()->create([
            'org_id' => $this->acme->id, 'name' => 'Jeddah', 'device_serial' => 'EGS-JED', 'industry' => 'Retail',
            'street' => 'Corniche Road', 'building_number' => '4321', 'district' => 'Al Hamra', 'city' => 'Jeddah', 'postal_code' => '23234',
        ]);
        $unit = app(Tills::class)->find($this->acme->id, $branch->id);
        $this->storeCcsid($unit->id);

        $result = app(UnitOnboarding::class)->runComplianceCheck($this->acme, $unit);

        $this->assertTrue($result['passed']);
        $this->assertSubmittedSigned();
    }

    /**
     * Stored as ZATCA returns it: the certificate's base64 DER, base64-encoded.
     */
    private function storeCcsid(?string $unitId): void
    {
        $body = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->device['certificate']);

        app(CredentialStore::class)->put($this->acme->id, $unitId, CredentialStore::CCSID, [
            'ccsid' => base64_encode($body),
            'secret' => 'compliance-secret',
            'privateKey' => $this->device['privateKey'],
            'requestId' => '1234567890',
        ]);
    }

    private function assertSubmittedSigned(): void
    {
        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();
        $this->assertCount(6, $sent);

        $previous = FatooraConfig::GENESIS_PIH;

        foreach ($sent as $index => $request) {
            $xml = base64_decode((string) $request['invoice']);
            $dom = new \DOMDocument;
            $dom->loadXML($xml);
            $path = new \DOMXPath($dom);
            $path->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
            $path->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $path->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

            $this->assertNotSame('', trim((string) $path->evaluate('string(//ds:SignatureValue)')), "sample {$index} is unsigned");
            $this->assertTrue(app(XadesVerifier::class)->verify($xml), "sample {$index}'s signature does not verify");
            $this->assertStringContainsString(
                (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->device['certificate']),
                (string) $path->evaluate('string(//ds:X509Certificate)'),
                "sample {$index} is not signed with the device's compliance certificate"
            );

            $qr = (string) $path->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject)");
            $this->assertNotSame('', $qr, "sample {$index} carries no QR");
            $this->assertSame(9, $this->tagCount(base64_decode($qr)), "sample {$index}'s QR is not the stamped nine tags");

            $this->assertSame((string) ($index + 1), $path->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID)"));
            $this->assertSame($previous, $path->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject)"));
            $this->assertSame(app(InvoiceHasher::class)->hash($xml), $request['invoiceHash']);

            $previous = $request['invoiceHash'];
        }
    }

    private function tagCount(string $tlv): int
    {
        $count = 0;

        for ($offset = 0; $offset < strlen($tlv); $offset += 2 + ord($tlv[$offset + 1])) {
            $count++;
        }

        return $count;
    }
}
