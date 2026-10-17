<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\SigningException;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\XadesSigner;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The key that signs is the key the certificate names.
 *
 * Nothing checked this, and nothing downstream would. The authority does not
 * verify `ds:SignatureValue` - established by submitting documents whose
 * signatures had been invalidated and watching them be accepted - so a
 * mismatched pair yields invoices that pass submission and are unverifiable
 * ever after. An auditor finds that, years of documents later, with nothing in
 * the logs to explain it.
 *
 * It was found by trying to verify a real sandbox document with OpenSSL and
 * getting INVALID. The cause there is not a defect: ZATCA's developer portal
 * hands every caller the same production certificate - byte for byte the one
 * in its own SDK sample - and nobody outside the authority holds its private
 * key. So a sandbox document is necessarily signed under a certificate that is
 * not the signer's, which is why that environment warns instead of refusing.
 *
 * Simulation and production issue a certificate against the CSR actually sent,
 * so there a mismatch can only mean the wrong key is in use, and signing with
 * it would be worse than failing.
 */
class SigningPairTest extends TestCase
{
    use SigningCredentials;

    private const INVOICE = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
        .' xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">'
        .'<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent>'
        .'<!-- SIGNATURE_PLACEHOLDER -->'
        .'</ext:ExtensionContent></ext:UBLExtension></ext:UBLExtensions>'
        .'<ID>INV-1</ID></Invoice>';

    public function test_a_matching_pair_signs(): void
    {
        config(['fatoora.environment' => 'production']);

        $credentials = $this->selfSignedCredentials();

        $this->assertStringContainsString(
            'SignatureValue',
            app(XadesSigner::class)->sign(self::INVOICE, $credentials['privateKey'], $credentials['certificate']),
            'A key and its own certificate were refused.'
        );
    }

    /**
     * Two separately generated pairs: the key is valid, the certificate is
     * valid, and they have nothing to do with each other - which is exactly
     * the shape of the mistake worth catching.
     */
    public function test_a_mismatched_pair_is_refused(): void
    {
        config(['fatoora.environment' => 'production']);

        $this->expectException(SigningException::class);
        $this->expectExceptionMessageMatches('/does not match the certificate/');

        app(XadesSigner::class)->sign(
            self::INVOICE,
            $this->selfSignedCredentials()['privateKey'],
            $this->selfSignedCredentials()['certificate'],
        );
    }

    /**
     * The sandbox cannot comply, so it warns rather than refusing - otherwise
     * the only end-to-end testing available without a taxpayer would stop.
     */
    public function test_the_sandbox_signs_anyway(): void
    {
        config(['fatoora.environment' => 'sandbox']);

        $this->assertStringContainsString(
            'SignatureValue',
            app(XadesSigner::class)->sign(
                self::INVOICE,
                $this->selfSignedCredentials()['privateKey'],
                $this->selfSignedCredentials()['certificate'],
            ),
            'The sandbox round trip cannot sign, so it can no longer be exercised.'
        );
    }
}
