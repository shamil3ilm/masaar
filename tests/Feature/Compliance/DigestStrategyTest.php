<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\XadesSigner;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The four ways the signed-properties digest can be computed.
 *
 * It is a setting because the authority and its own SDK disagree about the
 * answer, and only the authority can settle it. What was established locally:
 * this platform's signed-properties block is byte-identical to the one ZATCA's
 * signer produces for the same invoice with the same certificate, and the
 * default rule reproduces the digest the SDK records. All 26 SDK conformance
 * checks pass. The live API refuses every simplified document for this digest
 * regardless.
 *
 * So the strategies exist to be tried against the authority, one submission
 * each, and this holds them honest in the meantime: each produces a distinct
 * value of the length its encoding implies, and the default is the one the
 * SDK agrees with, so switching is deliberate rather than accidental.
 *
 * What a test cannot establish is which is right. That is
 * `fatoora:onboard --step=submit --digest=...` against the sandbox.
 */
class DigestStrategyTest extends TestCase
{
    use SigningCredentials;

    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * @return list<array{0: string, 1: int}>
     */
    public static function strategies(): array
    {
        return [
            // Decoded length: a digest written as hex and then base64 comes
            // back as 64 characters; one written as bytes, as 32.
            ['sdk', 64],
            ['sdk-bytes', 32],
            ['c14n', 32],
            ['c14n-hex', 64],
        ];
    }

    #[DataProvider('strategies')]
    public function test_each_strategy_has_its_own_length(string $strategy, int $bytes): void
    {
        config(['fatoora.signing.signed_properties_digest' => $strategy]);

        $digest = $this->digestFor();

        $this->assertSame(
            $bytes,
            strlen((string) base64_decode($digest, true)),
            "The {$strategy} digest is not {$bytes} bytes once decoded."
        );
    }

    /**
     * And the four are genuinely different values, so a submission that
     * changes the strategy changes what the authority sees. Two strategies
     * agreeing would make an experiment against the authority meaningless.
     */
    public function test_the_strategies_differ(): void
    {
        $digests = [];

        foreach (array_column(self::strategies(), 0) as $strategy) {
            config(['fatoora.signing.signed_properties_digest' => $strategy]);
            $digests[$strategy] = $this->digestFor();
        }

        $this->assertCount(
            4,
            array_unique($digests),
            'Two strategies produced the same digest: '.json_encode(array_map('strlen', $digests))
        );
    }

    /**
     * The default stays the SDK's, so the conformance suite keeps measuring
     * what it measured and a change of default is a deliberate act.
     */
    public function test_the_default_is_the_sdk_form(): void
    {
        $this->assertSame('sdk', config('fatoora.signing.signed_properties_digest'));
    }

    /**
     * The smallest document the signer will accept: a placeholder for the
     * signature to replace, and an ID so it is not empty.
     */
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

    /**
     * The digest this signature records for its signed properties.
     */
    private function digestFor(): string
    {
        $credentials = $this->selfSignedCredentials();

        $signed = app(XadesSigner::class)->sign(
            $this->invoiceXml(),
            $credentials['privateKey'],
            $credentials['certificate'],
        );

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($signed);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::DS);

        foreach ($xpath->query('//ds:SignedInfo/ds:Reference') as $reference) {
            if ($reference->getAttribute('URI') === '#xadesSignedProperties') {
                return trim((string) $xpath->query('./ds:DigestValue', $reference)->item(0)?->textContent);
            }
        }

        $this->fail('The signature records no signed-properties reference.');
    }
}
