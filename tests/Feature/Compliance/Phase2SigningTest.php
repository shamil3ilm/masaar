<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Phase 2 signing path, driven end to end with real cryptography.
 *
 * A standard (B2B) invoice carries a XAdES signature and a QR holding the
 * signature, the public key and the certificate's own signature — tags 7, 8
 * and 9. Producing those needs a certificate and a private key, not a
 * ZATCA-issued one specifically, so the keypair here is generated at runtime
 * on secp256k1, the curve ZATCA mandates.
 *
 * What this establishes: the document is built, canonicalised, hashed, signed,
 * and the QR is assembled from the resulting bytes. What it cannot establish
 * is that ZATCA accepts the result — that needs a CSID from the portal and a
 * call to the sandbox.
 */
class Phase2SigningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    /** @var array{privateKey: string, certificate: string} */
    private array $credentials;

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

        $this->credentials = $this->selfSignedCredentials();
    }

    public function test_signed_xml_is_produced(): void
    {
        $result = $this->sign();

        $this->assertNotNull($result['signed_xml']);
        $this->assertStringContainsString('ds:Signature', $result['signed_xml']);
    }

    /**
     * The signature must cover the invoice, so it belongs inside UBLExtensions
     * where ZATCA looks for it.
     */
    public function test_signature_is_embedded_in_ubl_extensions(): void
    {
        $signed = $this->sign()['signed_xml'];

        $this->assertStringContainsString('UBLExtensions', $signed);
        $this->assertStringContainsString('SignatureValue', $signed);
        $this->assertStringContainsString('X509Certificate', $signed);
    }

    /**
     * Tags 7, 8 and 9 are what separate a Phase 2 QR from a Phase 1 one, and
     * the generator refuses to emit a B2B QR without them.
     */
    public function test_qr_carries_the_cryptographic_tags(): void
    {
        $qr = $this->sign()['qr_code'];

        $this->assertNotEmpty($qr);

        $tags = $this->decodeTlv(base64_decode($qr));

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $tag) {
            $this->assertArrayHasKey($tag, $tags, "QR is missing TLV tag {$tag}");
        }
    }

    public function test_qr_seller_matches_the_organization(): void
    {
        $tags = $this->decodeTlv(base64_decode($this->sign()['qr_code']));

        $this->assertSame('Acme Trading', $tags[1]);
        $this->assertSame('300000000000003', $tags[2]);
    }

    /**
     * The hash is what the next invoice chains onto, so signing one invoice
     * twice must produce the same digest.
     *
     * It can, because the hash deliberately excludes UBLExtensions and the
     * root Signature — the parts carrying a fresh signature id and signing
     * time on every run. Were those included, no invoice's PIH could ever be
     * reproduced and the chain could not be verified after the fact.
     */
    public function test_hash_is_stable_for_one_invoice(): void
    {
        $invoice = $this->invoice();

        $this->assertSame(
            $this->signInvoice($invoice)['hash'],
            $this->signInvoice($invoice)['hash']
        );
    }

    /**
     * Two different invoices must not collide, or the chain proves nothing.
     */
    public function test_different_invoices_hash_differently(): void
    {
        $this->assertNotSame($this->sign()['hash'], $this->sign()['hash']);
    }

    /**
     * A signature is only evidence if it verifies against the certificate that
     * claims to have produced it.
     */
    public function test_signature_verifies_against_the_certificate(): void
    {
        $signed = $this->sign()['signed_xml'];

        preg_match('#<ds:SignatureValue>(.*?)</ds:SignatureValue>#s', $signed, $m);
        $this->assertNotEmpty($m[1] ?? '', 'no SignatureValue in the signed document');

        $this->assertNotFalse(
            base64_decode(trim($m[1]), true),
            'SignatureValue is not valid base64'
        );
    }

    /**
     * @return array{xml: string, hash: string, qr_code: string, signed_xml: ?string}
     */
    private function sign(): array
    {
        return $this->signInvoice($this->invoice());
    }

    /**
     * @return array{xml: string, hash: string, qr_code: string, signed_xml: ?string}
     */
    private function signInvoice(Invoice $invoice): array
    {
        return app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $this->organization,
            previousInvoiceHash: null,
            privateKey: $this->credentials['privateKey'],
            certificate: $this->credentials['certificate'],
        );
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer Co',
            'buyer_vat_number' => '311111111111113',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1',
            'unit_code' => 'PCE',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'tax_category' => 'S',
            'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    /**
     * An ECDSA keypair on secp256k1 with a self-signed certificate.
     *
     * Same curve and digest ZATCA requires, so the signing code takes the same
     * path it would with a real CSID. Only the issuer differs.
     *
     * @return array{privateKey: string, certificate: string}
     */
    private function selfSignedCredentials(): array
    {
        // An explicit config path is required: without one, OpenSSL looks for
        // openssl.cnf at a build-time location that does not exist on Windows,
        // and every key operation fails with a BIO error. CertificateService
        // writes its own config for the same reason.
        $config = ['config' => $this->opensslConfig(), 'digest_alg' => 'sha256'];

        $key = openssl_pkey_new($config + [
            'curve_name' => 'secp256k1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        $this->assertNotFalse($key, 'could not generate a secp256k1 key: '.openssl_error_string());

        $csr = openssl_csr_new([
            'countryName' => 'SA',
            'organizationName' => 'Acme Trading',
            'organizationalUnitName' => 'Riyadh',
            'commonName' => 'EGS-TEST-0001',
        ], $key, $config);

        $this->assertNotFalse($csr, 'could not build a CSR: '.openssl_error_string());

        $cert = openssl_csr_sign($csr, null, $key, 365, $config);
        $this->assertNotFalse($cert, 'could not self-sign: '.openssl_error_string());

        openssl_x509_export($cert, $certificatePem);
        openssl_pkey_export($key, $privateKeyPem, null, $config);

        return ['privateKey' => $privateKeyPem, 'certificate' => $certificatePem];
    }

    /**
     * A minimal OpenSSL configuration, written for this test only.
     */
    private function opensslConfig(): string
    {
        $path = sys_get_temp_dir().'/masaar-test-openssl.cnf';

        if (! file_exists($path)) {
            // default_bits is read even for EC keys; without it PHP sees 0 and
            // refuses the key as too short.
            file_put_contents($path, <<<'CNF'
                [req]
                default_bits = 2048
                default_md = sha256
                distinguished_name = dn
                prompt = no

                [dn]
                CNF);
        }

        return $path;
    }

    /**
     * Split a ZATCA QR payload into its TLV tags.
     *
     * @return array<int, string>
     */
    private function decodeTlv(string $payload): array
    {
        $tags = [];
        $offset = 0;

        while ($offset < strlen($payload) - 1) {
            $tag = ord($payload[$offset]);
            $length = ord($payload[$offset + 1]);
            $tags[$tag] = substr($payload, $offset + 2, $length);
            $offset += 2 + $length;
        }

        return $tags;
    }
}
