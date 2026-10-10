<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\QrCodeInjector;
use App\Domains\Compliance\Fatoora\Services\XadesSigner;
use DOMDocument;
use DOMXPath;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The digest a document records still describes the document that is sent.
 *
 * This is the check that was missing, and the gap cost weeks. The signature is
 * built, the SignedProperties digest is computed over the block as it stands,
 * and then the QR code is inserted - a DOM round trip of the whole document,
 * after it is signed. That round trip set formatOutput, which re-indented the
 * signature: the block grew from 726 bytes to 1130, so the digest in
 * SignedInfo described a block the document no longer carried.
 *
 * The authority refused every simplified document with "Invalid signed
 * properties hashing" and cleared every standard one, which is what made it so
 * hard to see: ZATCA stamps a standard document itself and only verifies the
 * seller's signature on a simplified one, because that is reported after the
 * customer already holds it. Four digest encodings were tried against the
 * authority and all four refused - the encoding was never the problem.
 *
 * Nor could the SDK find it: it recomputes the digest from whatever bytes it
 * is handed, so a document that contradicts itself still passes. All 26
 * conformance checks did. SignatureVerifiesTest did not catch it either,
 * because it verifies the signature over ds:SignedInfo - and SignedInfo was
 * intact. What nothing asserted was that the digest inside SignedInfo still
 * matched the block outside it.
 *
 * So this asserts the one property the authority actually checks, on the
 * document as finally emitted rather than as signed.
 */
class EmittedSignatureTest extends TestCase
{
    use SigningCredentials;

    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /**
     * Files that handle a document after it is signed. None of them may
     * pretty-print it; XmlBuilder may, because it runs before signing.
     */
    private const AFTER_SIGNING = [
        'app/Console/Commands/FatooraOnboarding.php',
        'app/Domains/Compliance/Fatoora/Services/XadesSigner.php',
        'app/Domains/Compliance/Fatoora/Services/DocumentBuilder.php',
        'app/Domains/Compliance/Fatoora/Services/QrCodeInjector.php',
    ];

    public function test_the_digest_describes_the_emitted_block(): void
    {
        $emitted = $this->signAndInjectQr();

        [$recorded, $block] = $this->signedProperties($emitted);

        $this->assertNotNull($recorded, 'The signature records no signed-properties digest.');

        $this->assertSame(
            $recorded,
            base64_encode(hash('sha256', $block)),
            'The digest recorded in ds:SignedInfo does not describe the '
            .'xades:SignedProperties block this document carries. Something '
            .'changed the block after it was signed - pretty-printing is how '
            .'it happened before. The authority refuses the document.'
        );
    }

    /**
     * And the QR really was inserted, so the assertion above is not passing
     * because the step it is meant to cover did nothing.
     */
    public function test_the_emitted_document_carries_a_qr(): void
    {
        $this->assertStringContainsString(
            'EmbeddedDocumentBinaryObject',
            $this->signAndInjectQr(),
            'No QR was inserted, so nothing round-tripped the signed document.'
        );
    }

    /**
     * Pretty-printing after signing is the fault itself, so it is named.
     *
     * A behavioural test covers the path it exercises; this covers the ones it
     * does not. Re-indenting a signed document invalidates every digest
     * recorded in it, and the invoice hash was already moved to after the QR
     * round trip to work around that - which left the signature as the only
     * thing still describing the unformatted document.
     */
    public function test_nothing_reformats_a_signed_document(): void
    {
        $offenders = [];

        foreach (self::AFTER_SIGNING as $path) {
            $source = (string) file_get_contents(__DIR__.'/../../../'.$path);

            if (preg_match('/formatOutput\s*=\s*true/', $source) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders)."\n\n"
            .'These files handle a document after it has been signed, and '
            .'setting formatOutput re-indents it - which moves the bytes every '
            .'recorded digest describes. Build the document pretty-printed '
            .'before signing if it has to be readable.');
    }

    /**
     * Sign a document and insert the QR, which is what submission sends.
     *
     * Both insertions are now one service, so this reaches it directly rather
     * than reflecting into the onboarding command. The two used to be separate
     * copies with different bugs - which is why there are two tests: this one
     * on the injector, and EmittedDigestsTest end to end through the path that
     * signs real invoices.
     */
    private function signAndInjectQr(): string
    {
        $credentials = $this->selfSignedCredentials();

        $signed = app(XadesSigner::class)->sign(
            $this->invoiceXml(),
            $credentials['privateKey'],
            $credentials['certificate'],
        );

        return app(QrCodeInjector::class)->inject($signed, 'QR-PLACEHOLDER');
    }

    /**
     * The recorded digest, and the bytes the authority digests to check it.
     *
     * @return array{0: ?string, 1: string}
     */
    private function signedProperties(string $xml): array
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::DS);

        $recorded = null;

        foreach ($xpath->query('//ds:SignedInfo/ds:Reference') as $reference) {
            if ($reference->getAttribute('URI') === '#xadesSignedProperties') {
                $recorded = trim((string) $xpath->query('./ds:DigestValue', $reference)->item(0)?->textContent);
            }
        }

        $block = (string) $dom->saveXML($xpath->query("//*[local-name()='SignedProperties']")->item(0));

        // The authority's rule, confirmed against three of its own published
        // samples: the element as the document carries it, with the prefixes
        // it uses declared on it, hashed and written as hex, then base64.
        if (! str_contains((string) strstr($block, '>', true), 'xmlns:xades=')) {
            $block = (string) preg_replace(
                '#^<xades:SignedProperties#',
                '<xades:SignedProperties xmlns:xades="'.self::XADES.'"',
                $block,
                1
            );
        }

        $block = (string) preg_replace(
            '#<ds:([A-Za-z0-9]+)(?![^>]*xmlns:ds=)#',
            '<ds:$1 xmlns:ds="'.self::DS.'"',
            $block
        );

        return [$recorded, $block];
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
