<?php

declare(strict_types=1);

/**
 * ZATCA InvoiceHasher compliance tests.
 *
 * Verifies SHA-256 C14N hashing against ZATCA specification:
 * "E-Invoicing Security Features — Section 3: Invoice Hash"
 *
 * Key rules verified:
 * - Hash algorithm is SHA-256 (not MD5, SHA-1, or SHA-512)
 * - Canonical XML (C14N) is applied before hashing
 * - UBLExtensions element is excluded from hash input
 * - ds:Signature element is excluded from hash input
 * - Hash is base64-encoded (standard, not URL-safe)
 * - PIH (Previous Invoice Hash) hashes the complete signed XML
 * - Whitespace normalisation: structurally identical XML → same hash
 */

use App\Domains\Compliance\Fatoora\Services\InvoiceHasher;

// ---------------------------------------------------------------------------
// Basic hash properties
// ---------------------------------------------------------------------------

it('returns base64-encoded output', function () {
    $hasher = new InvoiceHasher;
    $xml = '<Invoice><ID>INV-001</ID></Invoice>';

    $hash = $hasher->hash($xml);

    // Must be valid base64
    expect(base64_decode($hash, strict: true))->not->toBeFalse();
});

it('SHA-256 produces a 32-byte (256-bit) digest', function () {
    $hasher = new InvoiceHasher;
    $xml = '<Invoice><ID>INV-001</ID></Invoice>';

    $rawHash = base64_decode($hasher->hash($xml), strict: true);

    expect(strlen($rawHash))->toBe(32); // 256 bits ÷ 8 = 32 bytes
});

it('produces a deterministic hash for the same XML', function () {
    $hasher = new InvoiceHasher;
    $xml = '<Invoice><ID>INV-001</ID></Invoice>';

    expect($hasher->hash($xml))->toBe($hasher->hash($xml));
});

it('produces different hashes for different XML content', function () {
    $hasher = new InvoiceHasher;

    expect($hasher->hash('<Invoice><ID>INV-001</ID></Invoice>'))
        ->not->toBe($hasher->hash('<Invoice><ID>INV-002</ID></Invoice>'));
});

// ---------------------------------------------------------------------------
// C14N normalisation — structurally equivalent XML must hash identically
// ---------------------------------------------------------------------------

it('C14N: inline and multi-line XML produce the same hash', function () {
    $hasher = new InvoiceHasher;

    $inline = '<Invoice><ID>INV-001</ID><Total>1000</Total></Invoice>';
    $multiLine = "<Invoice>\n  <ID>INV-001</ID>\n  <Total>1000</Total>\n</Invoice>";

    expect($hasher->hash($inline))->toBe($hasher->hash($multiLine));
});

it('C14N: extra leading/trailing whitespace does not change the hash', function () {
    $hasher = new InvoiceHasher;

    $normal = '<Invoice><ID>INV-001</ID></Invoice>';
    $padded = "  \n<Invoice><ID>INV-001</ID></Invoice>\n  ";

    expect($hasher->hash($normal))->toBe($hasher->hash($padded));
});

// ---------------------------------------------------------------------------
// UBLExtensions exclusion (ZATCA spec requirement)
// ---------------------------------------------------------------------------

it('excludes UBLExtensions from the hash so adding a signature does not break it', function () {
    $hasher = new InvoiceHasher;

    $xmlWithoutExtensions = <<<'XML'
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
            <ID>INV-001</ID>
            <IssueDate>2024-11-15</IssueDate>
        </Invoice>
        XML;

    $xmlWithExtensions = <<<'XML'
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
            <ext:UBLExtensions>
                <ext:UBLExtension>
                    <ext:ExtensionContent>SomeSignatureData</ext:ExtensionContent>
                </ext:UBLExtension>
            </ext:UBLExtensions>
            <ID>INV-001</ID>
            <IssueDate>2024-11-15</IssueDate>
        </Invoice>
        XML;

    // After removing UBLExtensions, both XML documents should produce the same hash
    expect($hasher->hash($xmlWithoutExtensions))->toBe($hasher->hash($xmlWithExtensions));
});

// ---------------------------------------------------------------------------
// Signature exclusion (ds:Signature)
// ---------------------------------------------------------------------------

it('excludes ds:Signature element from the hash', function () {
    $hasher = new InvoiceHasher;

    $xmlWithoutSig = <<<'XML'
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">
            <ID>INV-001</ID>
        </Invoice>
        XML;

    $xmlWithSig = <<<'XML'
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
            <ds:Signature Id="urn:oasis:names:specification:ubl:signature:Invoice">
                <ds:SignedInfo><ds:Reference URI="#xades-ref0"/></ds:SignedInfo>
                <ds:SignatureValue>MEUCIQD…</ds:SignatureValue>
            </ds:Signature>
            <ID>INV-001</ID>
        </Invoice>
        XML;

    expect($hasher->hash($xmlWithoutSig))->toBe($hasher->hash($xmlWithSig));
});

// ---------------------------------------------------------------------------
// verify() helper
// ---------------------------------------------------------------------------

it('verify() returns true for a matching hash', function () {
    $hasher = new InvoiceHasher;
    $xml = '<Invoice><ID>INV-001</ID></Invoice>';
    $hash = $hasher->hash($xml);

    expect($hasher->verify($xml, $hash))->toBeTrue();
});

it('verify() returns false for a tampered hash', function () {
    $hasher = new InvoiceHasher;
    $xml = '<Invoice><ID>INV-001</ID></Invoice>';

    expect($hasher->verify($xml, 'tampered=='))->toBeFalse();
});

it('verify() returns false when XML content changes', function () {
    $hasher = new InvoiceHasher;
    $original = '<Invoice><ID>INV-001</ID><Total>1000</Total></Invoice>';
    $hash = $hasher->hash($original);
    $tampered = '<Invoice><ID>INV-001</ID><Total>9999</Total></Invoice>';

    expect($hasher->verify($tampered, $hash))->toBeFalse();
});

// ---------------------------------------------------------------------------
// hashForPih — PIH includes the complete signed XML
// ---------------------------------------------------------------------------

it('hashForPih produces a different hash than hash() for signed XML', function () {
    $hasher = new InvoiceHasher;

    $signedXml = <<<'XML'
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"
                 xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
            <ext:UBLExtensions>
                <ext:UBLExtension>
                    <ext:ExtensionContent>SignaturePayload</ext:ExtensionContent>
                </ext:UBLExtension>
            </ext:UBLExtensions>
            <ds:Signature>
                <ds:SignatureValue>ABC123</ds:SignatureValue>
            </ds:Signature>
            <ID>INV-001</ID>
        </Invoice>
        XML;

    $invoiceHash = $hasher->hash($signedXml);     // Excludes ext + sig
    $pihHash = $hasher->hashForPih($signedXml); // Includes everything

    // PIH chains the complete signed document; the two must differ
    expect($pihHash)->not->toBe($invoiceHash);
});

it('hashForPih returns valid base64', function () {
    $hasher = new InvoiceHasher;
    $xml = '<Invoice><ID>INV-001</ID></Invoice>';

    expect(base64_decode($hasher->hashForPih($xml), strict: true))->not->toBeFalse();
});

it('hashForPih is deterministic', function () {
    $hasher = new InvoiceHasher;
    $xml = '<Invoice><ID>INV-001</ID></Invoice>';

    expect($hasher->hashForPih($xml))->toBe($hasher->hashForPih($xml));
});
