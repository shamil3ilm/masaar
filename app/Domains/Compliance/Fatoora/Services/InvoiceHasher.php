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
        $dom->preserveWhiteSpace = false;
        Xml::load($dom, $xml);

        // Remove UBLExtensions element (contains signature) before hashing
        $this->removeUblExtensions($dom);

        // Remove Signature element if present at root level
        $this->removeSignature($dom);

        // Canonicalize (C14N) the document
        $canonicalized = $dom->documentElement->C14N(true, false);

        // SHA-256 hash, then base64 encode
        $hash = hash('sha256', $canonicalized, true);

        return base64_encode($hash);
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
        $dom->preserveWhiteSpace = false;
        Xml::load($dom, $signedXml);

        // For PIH, we hash the entire document including signature
        // but still use canonicalization for consistency
        $canonicalized = $dom->documentElement->C14N(true, false);

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
