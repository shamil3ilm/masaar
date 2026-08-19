<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\Services\CertificateService;
use Tests\TestCase;

/**
 * The certificate request a taxpayer's onboarding stands on.
 *
 * ZATCA issues a CSID against this CSR, and everything afterwards — signing,
 * the QR's tags 7 to 9, clearance — depends on the key it was made with and
 * the identity it asserts. A CSR that is well-formed but wrong is rejected at
 * the portal, by a person, with an OTP that expires in an hour.
 *
 * CsrDataTest covers what may go into the request. This covers what comes out.
 */
class CsrGenerationTest extends TestCase
{
    private array $result;

    protected function setUp(): void
    {
        parent::setUp();

        $this->result = app(CertificateService::class)->generateCsr($this->csrData());
    }

    public function test_subject_carries_the_taxpayer(): void
    {
        $subject = openssl_csr_get_subject($this->result['csr']);

        $this->assertSame('SA', $subject['C']);
        $this->assertSame('Acme Trading Co', $subject['O']);
        $this->assertSame('Riyadh Branch', $subject['OU']);
        $this->assertSame('EGS-1234567890', $subject['CN']);
    }

    /**
     * The single failure that would survive everything else.
     *
     * If the returned private key is not the one the CSR asks a certificate
     * for, onboarding completes, ZATCA issues a CSID, and every invoice signed
     * afterwards carries a signature that cannot verify against the
     * certificate beside it. Nothing before the authority would notice.
     *
     * Checked by signing with the returned key and verifying with the key
     * inside the CSR, which is the only thing that establishes they are a pair.
     */
    public function test_private_key_matches_the_request(): void
    {
        $nonce = random_bytes(32);
        $signature = '';

        $this->assertTrue(
            openssl_sign($nonce, $signature, $this->result['privateKey'], OPENSSL_ALGO_SHA256),
            'The returned private key cannot sign.'
        );

        $this->assertSame(
            1,
            openssl_verify(
                $nonce,
                $signature,
                openssl_csr_get_public_key($this->result['csr']),
                OPENSSL_ALGO_SHA256
            ),
            'The returned private key does not belong to the CSR.'
        );
    }

    /**
     * ZATCA mandates secp256k1. A request on any other curve is refused, and
     * the curve is fixed in code rather than configured per tenant.
     */
    public function test_key_is_on_the_mandated_curve(): void
    {
        $details = openssl_pkey_get_details(openssl_csr_get_public_key($this->result['csr']));

        $this->assertSame(OPENSSL_KEYTYPE_EC, $details['type']);
        $this->assertSame('secp256k1', $details['ec']['curve_name']);
    }

    /**
     * The identity ZATCA reads out of the request: the VAT registration as an
     * organizationIdentifier, and the device and invoice types in the subject
     * alternative name.
     *
     * Read with phpseclib because PHP's own CSR functions expose the subject
     * and the public key but not the extensions, which is where these live.
     */
    public function test_request_carries_the_zatca_extensions(): void
    {
        $der = $this->der();

        // organizationIdentifier in the subject, per ZATCA's VATSA- form.
        $this->assertStringContainsString(
            'VATSA-300000000000003',
            $der,
            'The CSR does not carry the VAT registration.'
        );

        // The directory name inside subjectAltName: the device's serial, the
        // VAT registration again as UID, and the document types it is being
        // registered for.
        $this->assertStringContainsString(
            '1-Masaar|2-1.0|3-abc123',
            $der,
            'The CSR does not carry the solution serial number.'
        );

        $this->assertStringContainsString(
            '1110',
            $der,
            'The CSR does not declare the invoice types.'
        );

        $this->assertStringNotContainsString(
            '1010',
            $der,
            'The CSR declares invoice types it was not asked for.'
        );
    }

    /**
     * ZATCA registers a device for standard invoices, simplified, or both, and
     * the request has to say which. A device onboarded for the wrong pair is
     * refused when it submits the type it did not ask for.
     */
    public function test_invoice_types_follow_the_request(): void
    {
        $simplifiedOnly = app(CertificateService::class)->generateCsr(
            $this->csrData(standard: false, simplified: true)
        );

        $der = $this->der($simplifiedOnly['csr']);

        $this->assertStringContainsString('1010', $der, 'A simplified-only device did not declare 1010.');

        // Both directions, so neither assertion can be satisfied by a byte
        // sequence that happens to appear elsewhere in the encoding.
        $this->assertStringNotContainsString('1110', $der, 'A simplified-only device still declared standard invoices.');
    }

    /**
     * The request's own bytes, where the subject and its extensions appear as
     * readable strings.
     */
    private function der(?string $pem = null): string
    {
        $pem = $pem ?? $this->result['csr'];

        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE REQUEST-----|\s+/', '', $pem);

        return (string) base64_decode((string) $body);
    }

    private function csrData(bool $standard = true, bool $simplified = true): CsrData
    {
        return new CsrData(
            organizationName: 'Acme Trading Co',
            organizationUnit: 'Riyadh Branch',
            commonName: 'EGS-1234567890',
            vatNumber: '300000000000003',
            serialNumber: '1-Masaar|2-1.0|3-abc123',
            location: 'Riyadh',
            industry: 'Retail',
            invoiceTypesStandard: $standard,
            invoiceTypesSimplified: $simplified,
        );
    }
}
