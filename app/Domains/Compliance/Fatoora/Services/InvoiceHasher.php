<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Support\Xml;
use DOMDocument;
use DOMXPath;

/**
 * ZATCA invoice hash generator.
 *
 * Creates SHA-256 hash of invoice XML for compliance verification.
 * Follows ZATCA specification for canonical XML (C14N) hashing,
 * excluding UBLExtensions (signature) section.
 */
class InvoiceHasher
{
    private const EXT_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const SIG_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * Generate SHA-256 hash of invoice XML per ZATCA specification.
     *
     * ZATCA requires:
     * 1. Canonicalization (C14N) of the XML
     * 2. Exclusion of UBLExtensions element (contains signature)
     * 3. SHA-256 hash, base64 encoded
     *
     * @param  string  $xml  Invoice XML content
     * @return string Base64-encoded hash
     */
    public function hash(string $xml): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Whitespace is content to C14N, and dropping it changes the digest.
        // This was false, which is one of the two reasons ZATCA answered "the
        // invoice hash API body does not match the (calculated) Hash of the
        // XML" for every document ever sent.
        $dom->preserveWhiteSpace = true;
        Xml::load($dom, $xml);

        // Removed before hashing: the signature the hash goes on to protect,
        // and the QR, which carries that same hash. ZATCA removes all three
        // when it recomputes, so a document keeping any of them hashes to
        // something the authority will not arrive at.
        $this->removeUblExtensions($dom);
        $this->removeSignature($dom);
        $this->removeQrReference($dom);

        // Inclusive C14N 1.1, not exclusive. The second reason for the
        // mismatch: exclusive canonicalization drops namespace declarations
        // the document does not visibly use, and ZATCA hashes with them.
        $canonicalized = $dom->documentElement->C14N(false, false);

        $hash = hash('sha256', $canonicalized, true);

        return base64_encode($hash);
    }

    /**
     * Remove the QR AdditionalDocumentReference.
     *
     * It carries the hash being computed, so leaving it in makes the digest
     * depend on itself. Absent before signing, present afterwards, and
     * excluded either way.
     */
    private function removeQrReference(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        foreach ($xpath->query('//cac:AdditionalDocumentReference[cbc:ID="QR"]') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Generate hash for Previous Invoice Hash (PIH) calculation.
     *
     * For PIH, we need to hash the complete signed XML of the previous invoice.
     * This uses the same canonicalization but includes the signature.
     *
     * @param  string  $signedXml  Complete signed invoice XML
     * @return string Base64-encoded hash for PIH
     */
    public function hashForPih(string $signedXml): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        Xml::load($dom, $signedXml);

        // Same canonicalization as hash(): inclusive, whitespace preserved.
        // What differs is the subject — the whole signed document.
        $canonicalized = $dom->documentElement->C14N(false, false);

        $hash = hash('sha256', $canonicalized, true);

        return base64_encode($hash);
    }

    /**
     * Generate hash from invoice data (without XML).
     * Useful for simple hash generation.
     */
    public function hashFromData(array $data): string
    {
        // Create deterministic string from data
        ksort($data);
        $content = json_encode($data, JSON_UNESCAPED_UNICODE);

        $hash = hash('sha256', $content, true);

        return base64_encode($hash);
    }

    /**
     * Verify hash matches content.
     */
    public function verify(string $xml, string $expectedHash): bool
    {
        return $this->hash($xml) === $expectedHash;
    }

    /**
     * Remove UBLExtensions element from document.
     *
     * UBLExtensions contains the signature and must be excluded
     * when calculating the invoice hash per ZATCA specification.
     */
    private function removeUblExtensions(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', self::EXT_NS);

        $extensions = $xpath->query('//ext:UBLExtensions');

        foreach ($extensions as $extension) {
            $extension->parentNode->removeChild($extension);
        }
    }

    /**
     * Remove ds:Signature element if present at document level.
     */
    private function removeSignature(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('sig', self::SIG_NS);
        $xpath->registerNamespace('cac', self::CAC_NS);

        // cac:Signature, the UBL element, is the one ZATCA excludes. This
        // removed ds:Signature and UBLDocumentSignatures and left it in place,
        // so the digest covered an element the authority had already taken out.
        foreach ($xpath->query('//cac:Signature') ?: [] as $signature) {
            $signature->parentNode?->removeChild($signature);
        }

        // Remove any Signature elements
        $signatures = $xpath->query('//ds:Signature');
        foreach ($signatures as $sig) {
            $sig->parentNode->removeChild($sig);
        }

        // Also remove UBLDocumentSignatures if present
        $docSigs = $xpath->query('//sig:UBLDocumentSignatures');
        foreach ($docSigs as $docSig) {
            $docSig->parentNode->removeChild($docSig);
        }
    }
}
