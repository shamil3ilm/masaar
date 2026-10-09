<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * That the signature on a document actually verifies.
 *
 * ZATCA's SDK has a signatureValue check and it cannot be used for this. It
 * fails on the authority's own shipped samples - Simplified_Invoice.xml
 * validates XSD, EN, KSA and PIH and then reports "signatureValue: wrong
 * signature Value" - because those files were pretty-printed after they were
 * signed, so the SignedInfo in the file is no longer the SignedInfo that was
 * signed. A check that rejects the authority's reference documents says
 * nothing about ours, so ZatcaConformanceTest excludes it and this test
 * establishes the same property from first principles instead.
 *
 * The property: the bytes in ds:SignatureValue are an ECDSA signature, made
 * with the key behind the certificate the document carries, over the
 * canonicalised ds:SignedInfo. That is what a verifier does, so doing it here
 * with OpenSSL needs no SDK and no Java, and it runs on every push rather
 * than only where the SDK is unpacked.
 *
 * It is checked on the document as finally emitted, after the QR has been
 * injected and the whole thing re-serialised, because that round trip is what
 * would silently invalidate a signature that was right when it was made.
 */
class SignatureVerifiesTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private Organization $organization;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create(['email' => 'biller@masaar.test']);
        $user->organizations()->attach($this->organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'biller@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function documents(): array
    {
        return [['simplified'], ['standard']];
    }

    #[DataProvider('documents')]
    public function test_the_signature_verifies(string $type): void
    {
        $xml = $this->build($type);
        $xpath = $this->xpath($xml);

        $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        $this->assertInstanceOf(DOMElement::class, $signedInfo, 'The document carries no ds:SignedInfo.');

        $signature = (string) base64_decode(
            (string) preg_replace('/\s+/', '', (string) $xpath->query('//ds:SignatureValue')->item(0)?->textContent),
            true
        );

        $this->assertNotSame('', $signature, 'The document carries no signature.');

        $key = openssl_pkey_get_public($this->certificatePem($xpath));
        $this->assertNotFalse($key, 'The certificate in the document could not be read.');

        // C14N() on the node in the tree, which is what the signer canonicalised.
        $this->assertSame(
            1,
            openssl_verify($signedInfo->C14N(), $signature, $key, OPENSSL_ALGO_SHA256),
            'The signature does not verify over the canonicalised SignedInfo '
                .'with the certificate this document carries.'
        );
    }

    /**
     * A signature over a SignedInfo that has been altered must not verify, or
     * the assertion above would pass for any bytes at all.
     */
    #[DataProvider('documents')]
    public function test_a_changed_digest_breaks_it(string $type): void
    {
        $xml = $this->build($type);
        $xpath = $this->xpath($xml);

        $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        $this->assertInstanceOf(DOMElement::class, $signedInfo);

        $digest = $xpath->query('//ds:SignedInfo//ds:DigestValue')->item(0);
        $this->assertInstanceOf(DOMElement::class, $digest);
        $digest->textContent = base64_encode(hash('sha256', 'not the document', true));

        $signature = (string) base64_decode(
            (string) preg_replace('/\s+/', '', (string) $xpath->query('//ds:SignatureValue')->item(0)?->textContent),
            true
        );

        $key = openssl_pkey_get_public($this->certificatePem($xpath));
        $this->assertNotFalse($key);

        $this->assertNotSame(
            1,
            openssl_verify($signedInfo->C14N(), $signature, $key, OPENSSL_ALGO_SHA256),
            'A document whose digest was replaced still verified, so the check proves nothing.'
        );
    }

    private function certificatePem(DOMXPath $xpath): string
    {
        $base64 = (string) preg_replace(
            '/\s+/',
            '',
            (string) $xpath->query('//ds:X509Certificate')->item(0)?->textContent
        );

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($base64, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /**
     * The document as it is finally emitted, QR injected and re-serialised.
     */
    private function build(string $type): string
    {
        $payload = [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => $type,
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer Co',
            'lines' => [[
                'description' => 'Item',
                'quantity' => 1,
                'unit_price' => '1000.00',
                'tax_rate' => '15',
                'tax_category' => 'S',
            ]],
        ];

        if ($type === 'standard') {
            // A standard invoice is B2B, so BT-46 and BT-50 apply: the buyer's
            // VAT number and address are required.
            $payload += [
                'buyer_vat_number' => '399999999800003',
                'buyer_address' => [
                    'street' => 'Prince Sultan Road',
                    'building_number' => '5678',
                    'district' => 'Al Malaz',
                    'city' => 'Riyadh',
                    'postal_code' => '12345',
                    'country_code' => 'SA',
                ],
            ];
        }

        $id = $this->withToken($this->token)
            ->postJson('/api/invoices', $payload)
            ->assertSuccessful()
            ->json('data.invoice.id');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
        $credentials = $this->selfSignedCredentials();

        $built = app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $this->organization,
            previousInvoiceHash: null,
            privateKey: $credentials['privateKey'],
            certificate: $credentials['certificate'],
        );

        return (string) ($built['signed_xml'] ?? $built['xml']);
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::DS);

        return $xpath;
    }
}
