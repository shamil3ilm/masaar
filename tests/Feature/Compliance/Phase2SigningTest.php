<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Compliance\Fatoora\Services\XadesSigner;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
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
    use SigningCredentials;

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
     * The signature belongs inside UBLExtensions, where ZATCA looks for it.
     *
     * This used to assert that the string "UBLExtensions" appeared somewhere
     * in the document. It always did — the document reference carries an XPath
     * transform whose text is `not(//ancestor-or-self::ext:UBLExtensions)`. So
     * the assertion held while the signature sat directly under Invoice,
     * because the scaffold was never built and the signer had nowhere to put
     * it. The location is now read from the tree.
     */
    public function test_signature_is_embedded_in_ubl_extensions(): void
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($this->sign()['signed_xml']);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace(
            'ext',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2'
        );

        $signatures = $xpath->query(
            '/*/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/ds:Signature'
        );

        $this->assertSame(1, $signatures->length, 'The signature is not inside ExtensionContent.');

        $signature = $signatures->item(0);

        $this->assertSame(1, $xpath->query('ds:SignatureValue', $signature)->length);
        $this->assertSame(1, $xpath->query('ds:KeyInfo/ds:X509Data/ds:X509Certificate', $signature)->length);
    }

    /**
     * XML-DSig fixes the order of a Signature's children. A verifier that
     * reads them positionally rejects a document that lists them otherwise.
     */
    public function test_signature_children_are_in_order(): void
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($this->sign()['signed_xml']);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $children = [];

        foreach ($xpath->query('//ds:Signature')->item(0)->childNodes as $child) {
            $children[] = $child->nodeName;
        }

        $this->assertSame(
            ['ds:SignedInfo', 'ds:SignatureValue', 'ds:KeyInfo', 'ds:Object'],
            $children
        );
    }

    /**
     * Tags 7, 8 and 9 are what separate a Phase 2 QR from a Phase 1 one, and
     * the generator refuses to emit a B2B QR without them.
     */
    public function test_qr_carries_the_cryptographic_tags(): void
    {
        $tags = $this->decodeTlv(base64_decode($this->sign()['qr_code']));

        foreach (range(1, 9) as $tag) {
            $this->assertArrayHasKey($tag, $tags, "QR is missing TLV tag {$tag}");
        }
    }

    /**
     * Tags 6 and 7 have to be the hash and signature of the document beside
     * them, not merely present.
     *
     * A QR built from a stale or unrelated signature satisfies "the tag
     * exists" and fails at the authority, which validates the pair against
     * each other. Both are compared to the document rather than recomputed the
     * way the generator computed them, so this cannot agree with itself.
     */
    public function test_qr_matches_the_document(): void
    {
        $result = $this->sign();
        $tags = $this->decodeTlv(base64_decode($result['qr_code']));

        $this->assertSame(
            base64_decode($result['hash']),
            $tags[6],
            'QR tag 6 is not the invoice hash.'
        );

        preg_match('#<ds:SignatureValue>(.*?)</ds:SignatureValue>#s', $result['signed_xml'], $m);

        $this->assertSame(
            base64_decode(trim($m[1])),
            $tags[7],
            'QR tag 7 is not the signature in the document.'
        );
    }

    /**
     * Tag 8 is the signing public key, as an uncompressed secp256k1 point.
     *
     * Derived here from the certificate with OpenSSL rather than through the
     * code that wrote the tag, so the two agreeing means something.
     */
    public function test_qr_key_matches_the_certificate(): void
    {
        $tags = $this->decodeTlv(base64_decode($this->sign()['qr_code']));

        $details = openssl_pkey_get_details(
            openssl_pkey_get_public($this->credentials['certificate'])
        );

        $point = "\x04"
            .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $this->assertSame($point, $tags[8], 'QR tag 8 is not the certificate public key.');
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
     *
     * This used to assert that SignatureValue was present and was valid
     * base64. Both hold for any random bytes, so the check the docblock
     * described was never made. Verification canonicalises SignedInfo again
     * and puts it, the signature and the embedded certificate through ECDSA —
     * which is what a verifier at ZATCA does.
     */
    public function test_signature_verifies_against_the_certificate(): void
    {
        $signed = $this->sign()['signed_xml'];

        $this->assertTrue(
            app(XadesSigner::class)->verify($signed),
            'The signature does not verify against the certificate in the document.'
        );
    }

    /**
     * Verification has to be able to fail, or the test above proves nothing —
     * verify() swallows its own exceptions and would otherwise be satisfied by
     * a signature over the empty string, which is what it used to receive.
     *
     * Altering SignedInfo is what breaks the signature specifically: it is the
     * only element the ECDSA signature covers.
     */
    public function test_altering_signed_info_breaks_the_signature(): void
    {
        $signed = $this->sign()['signed_xml'];

        $tampered = preg_replace(
            '#(<ds:SignedInfo.*?<ds:DigestValue>)[^<]+#s',
            '$1'.base64_encode(str_repeat("\0", 32)),
            $signed,
            1
        );

        $this->assertNotSame($signed, $tampered, 'the tamper did not change the document');

        $this->assertFalse(
            app(XadesSigner::class)->verify($tampered),
            'SignedInfo was altered after signing and the signature still verified.'
        );
    }

    /**
     * A signature covers what its references say it covers. If a digest no
     * longer matches, the signature is over a document that no longer exists.
     */
    public function test_signed_references_match_their_digests(): void
    {
        $result = app(XadesSigner::class)->verifyReferences($this->sign()['signed_xml']);

        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    /**
     * Editing the invoice leaves the signature intact and the reference stale,
     * which is why both checks exist: verify() answers "was SignedInfo
     * altered", verifyReferences() answers "is this still the document that
     * was signed". A verifier needs both to conclude anything.
     */
    public function test_editing_the_invoice_breaks_its_reference(): void
    {
        $signed = $this->sign()['signed_xml'];

        $tampered = str_replace('Buyer Co', 'Rival Co', $signed);

        $this->assertNotSame($signed, $tampered, 'the tamper did not change the document');

        $result = app(XadesSigner::class)->verifyReferences($tampered);

        $this->assertFalse($result['valid'], 'An edited invoice still matched its reference digest.');
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
     * Timestamping engages from configuration.
     *
     * XadesSigner implements RFC 3161 in full — the request, the response
     * parsed and embedded — and nothing ever called withTimestampAuthority(),
     * so the URL stayed null and no document was timestamped.
     * fatoora.tsa.enabled, .url, .username and .password were read nowhere:
     * ZATCA_TSA_ENABLED=true turned nothing on.
     */
    public function test_timestamping_engages_from_config(): void
    {
        config([
            'fatoora.tsa.enabled' => true,
            'fatoora.tsa.url' => 'https://tsa.example.test/rfc3161',
        ]);

        $url = new \ReflectionProperty(XadesSigner::class, 'tsaUrl');

        $this->assertSame('https://tsa.example.test/rfc3161', $url->getValue(app(XadesSigner::class)));
    }

    /**
     * Disabled means disabled, even with a URL configured.
     */
    public function test_timestamping_stays_off_when_disabled(): void
    {
        config([
            'fatoora.tsa.enabled' => false,
            'fatoora.tsa.url' => 'https://tsa.example.test/rfc3161',
        ]);

        $url = new \ReflectionProperty(XadesSigner::class, 'tsaUrl');

        $this->assertNull($url->getValue(app(XadesSigner::class)));
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
