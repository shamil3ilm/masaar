<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\XadesSigner;
use DOMDocument;
use DOMXPath;
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

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        parent::setUp();

        $credentials = $this->selfSignedCredentials();

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

        $this->assertSame(
            base64_encode(hash('sha256', (string) base64_decode($embedded), true)),
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
            (string) $parsed['serialNumber'],
            $this->text('//xades:IssuerSerial/ds:X509SerialNumber'),
            'The signed properties name a different serial than the certificate.'
        );

        $this->assertStringContainsString(
            'CN='.$parsed['issuer']['CN'],
            $this->text('//xades:IssuerSerial/ds:X509IssuerName')
        );
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
            ->query('//ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]')
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
     * ZATCA reads the signing time as UTC. A local-time stamp is off by the
     * offset, which for Riyadh is three hours — well outside the tolerance the
     * authority allows between a document and its submission.
     */
    public function test_signing_time_is_utc_and_current(): void
    {
        $signingTime = $this->text('//xades:SigningTime');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $signingTime,
            'SigningTime is not a UTC instant in ZATCA form.'
        );

        $this->assertLessThan(
            120,
            abs(strtotime($signingTime) - time()),
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
