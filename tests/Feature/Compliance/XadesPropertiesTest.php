<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\XadesSigner;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use phpseclib3\Math\BigInteger;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The signed properties are the half of a XAdES signature that says who signed
 * and when, and they are covered by their own reference — so a value that
 * disagrees with the certificate beside it invalidates the signature rather
 * than merely misreporting.
 *
 * What is checked here is internal consistency: the digest matches the
 * certificate actually embedded, the issuer and serial are that certificate's,
 * and the reference in SignedInfo points at the properties it claims to cover.
 * Whether ZATCA wants the digest over the DER or over the base64 body is a
 * question only their fixtures settle, and that is W-5.1.
 */
class XadesPropertiesTest extends TestCase
{
    use SigningCredentials;

    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /**
     * Past 32 bits, which a ZATCA-issued serial always is, OpenSSL prints the
     * serial as hex.
     */
    private const SERIAL = 5_000_000_000;

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        parent::setUp();

        $credentials = $this->selfSignedCredentials(self::SERIAL);

        $signed = app(XadesSigner::class)->sign(
            $this->invoiceXml(),
            $credentials['privateKey'],
            $credentials['certificate'],
        );

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($signed);

        $this->xpath = new DOMXPath($dom);
        $this->xpath->registerNamespace('ds', self::DS);
        $this->xpath->registerNamespace('xades', self::XADES);
    }

    /**
     * The digest has to be of the certificate the document carries. If it is
     * of some other certificate, a verifier resolving the signing certificate
     * from KeyInfo computes a different digest and rejects the signature.
     */
    public function test_cert_digest_matches_the_certificate(): void
    {
        $embedded = $this->text('//ds:KeyInfo/ds:X509Data/ds:X509Certificate');

        // Taken over the base64 text rather than the bytes it encodes, and
        // written as hex before being base64'd. Both are what ZATCA's own
        // signed samples carry: the value in Data/Samples decodes to
        // sixty-four hex characters and matches the digest of the sample's
        // certificate text, not of its DER.
        $this->assertSame(
            base64_encode(hash('sha256', $embedded)),
            $this->text('//xades:CertDigest/ds:DigestValue'),
            'CertDigest is not the digest of the certificate in KeyInfo.'
        );
    }

    public function test_issuer_and_serial_are_the_certificate(): void
    {
        $certificate = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($this->text('//ds:KeyInfo/ds:X509Data/ds:X509Certificate'), 64, "\n")
            .'-----END CERTIFICATE-----';

        $parsed = openssl_x509_parse($certificate);

        $this->assertSame(
            (new BigInteger($parsed['serialNumberHex'], 16))->toString(),
            $this->text('//xades:IssuerSerial/ds:X509SerialNumber'),
            'The signed properties name a different serial than the certificate.'
        );

        $this->assertStringContainsString(
            'CN='.$parsed['issuer']['CN'],
            $this->text('//xades:IssuerSerial/ds:X509IssuerName')
        );
    }

    /**
     * The schema types X509SerialNumber as an integer. OpenSSL's 0x-prefixed
     * hex failed ZATCA's schema check for every document signed with a
     * certificate whose serial passes 32 bits.
     */
    public function test_serial_is_decimal(): void
    {
        $this->assertSame((string) self::SERIAL, $this->text('//xades:IssuerSerial/ds:X509SerialNumber'));
    }

    /**
     * The reference covering the signed properties resolves by Id. If the two
     * disagree the reference covers nothing, and the properties are outside
     * the signature while appearing to be inside it.
     */
    public function test_reference_points_at_the_signed_properties(): void
    {
        $id = $this->xpath->query('//xades:SignedProperties')->item(0)->getAttribute('Id');

        $uri = $this->xpath
            // The type ZATCA's own signed samples carry for this
            // reference, which is not the XAdES one for signed properties.
            ->query('//ds:Reference[@Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties"]')
            ->item(0)
            ->getAttribute('URI');

        $this->assertSame('#'.$id, $uri);
    }

    /**
     * QualifyingProperties names the signature it qualifies.
     */
    public function test_properties_target_the_signature(): void
    {
        $signatureId = $this->xpath->query('//ds:Signature')->item(0)->getAttribute('Id');

        $this->assertSame(
            '#'.$signatureId,
            $this->xpath->query('//xades:QualifyingProperties')->item(0)->getAttribute('Target')
        );
    }

    /**
     * The signing time is written the way the authority writes it: local time
     * on the Kingdom's clock, with no timezone designator.
     *
     * This asserted the opposite - UTC with a trailing Z - and the reasoning
     * was that a bare stamp is ambiguous and three hours out for a reader who
     * assumes Riyadh. The authority disagreed, and it is the authority's
     * format: every simplified document was refused by the live API with
     * "Invalid signed properties hashing", and that Z was the last difference
     * between this block and the one ZATCA's own signer produces for the same
     * invoice.
     *
     * The SDK could not have caught it. It recomputes the digest from the
     * bytes it is handed, so either form hashes consistently to it - all 26
     * conformance tests passed while the live API refused the documents. A
     * validator that reads what you wrote cannot catch a disagreement about
     * what to write; only the authority can.
     */
    public function test_signing_time_matches_the_authority(): void
    {
        $signingTime = $this->text('//xades:SigningTime');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
            $signingTime,
            'SigningTime is not local time without a designator, which is the form ZATCA signs with.'
        );

        // Read on the Kingdom's clock, because that is what it is. Read as
        // UTC it would be three hours ahead, which is the ambiguity the Z was
        // there to remove and the reason the rest of the document moved to
        // this clock too.
        $signedAt = Carbon::createFromFormat('Y-m-d\TH:i:s', $signingTime, 'Asia/Riyadh');

        $this->assertNotFalse($signedAt, 'SigningTime could not be read as an instant.');

        $this->assertLessThan(
            120,
            abs($signedAt->getTimestamp() - time()),
            'SigningTime is not the moment the document was signed.'
        );

    }

    private function text(string $query): string
    {
        $node = $this->xpath->query($query)->item(0);

        $this->assertNotNull($node, "missing: {$query}");

        return trim($node->textContent);
    }

    private function invoiceXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">'
            .'<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent>'
            .'<!-- SIGNATURE_PLACEHOLDER -->'
            .'</ext:ExtensionContent></ext:UBLExtension></ext:UBLExtensions>'
            .'<ID>INV-1</ID></Invoice>';
    }
}
