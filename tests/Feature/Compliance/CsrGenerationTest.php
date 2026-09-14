<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\Services\CsrBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The key and certificate request a taxpayer's onboarding stands on.
 *
 * ZATCA issues a CSID against this CSR, and everything afterwards — signing,
 * the QR's tags 7 to 9, clearance — depends on the key it was made with and
 * the identity it asserts. A CSR that is well-formed but wrong is rejected at
 * the portal, by a person, with an OTP that expires in an hour.
 *
 * CsrBuilderTest covers the request's structure. This covers the key generated
 * with it and the template chosen for it.
 */
class CsrGenerationTest extends TestCase
{
    private array $result;

    protected function setUp(): void
    {
        parent::setUp();

        $this->result = app(CsrBuilder::class)->generate($this->csrData(), CsrBuilder::TEMPLATE_SANDBOX);
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
     * The identity ZATCA reads out of the subject alternative name: the
     * device's serial, the VAT registration and the invoice types.
     */
    public function test_request_carries_the_registration(): void
    {
        $der = $this->der();

        $this->assertStringContainsString('1-Masaar|2-1.0|3-abc123', $der, 'The CSR does not carry the solution serial number.');
        $this->assertStringContainsString('300000000000003', $der, 'The CSR does not carry the VAT registration.');
        $this->assertStringContainsString('1100', $der, 'The CSR does not declare the invoice types.');
    }

    /**
     * ZATCA registers a device for standard invoices, simplified, or both, and
     * the request has to say which. A device onboarded for the wrong pair is
     * refused when it submits the type it did not ask for.
     */
    public function test_invoice_types_follow_the_request(): void
    {
        $simplifiedOnly = app(CsrBuilder::class)->generate(
            $this->csrData(standard: false, simplified: true),
            CsrBuilder::TEMPLATE_SANDBOX
        );

        $der = $this->der($simplifiedOnly['csr']);

        $this->assertStringContainsString('0100', $der, 'A simplified-only device did not declare 0100.');
        $this->assertStringNotContainsString('1100', $der, 'A simplified-only device still declared standard invoices.');
    }

    public static function environments(): iterable
    {
        yield 'production' => ['production', 'ZATCA-Code-Signing'];
        yield 'simulation' => ['simulation', 'PREZATCA-Code-Signing'];
        yield 'sandbox' => ['sandbox', 'TSTZATCA-Code-Signing'];
        yield 'unknown falls back to sandbox' => ['staging', 'TSTZATCA-Code-Signing'];
    }

    /**
     * The template selects ZATCA's environment. A request sent to one
     * environment carrying another's template is refused.
     */
    #[DataProvider('environments')]
    public function test_template_follows_the_environment(string $environment, string $template): void
    {
        $this->assertSame($template, CsrBuilder::templateFor($environment));
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
