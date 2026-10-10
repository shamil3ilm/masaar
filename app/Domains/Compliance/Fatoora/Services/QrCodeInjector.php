<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Support\Xml;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Puts the QR code into a signed document, without disturbing the signature.
 *
 * There were two of these - one in DocumentBuilder for real invoices, one in
 * the onboarding command for the compliance set - doing the same job with
 * different bugs. The command's reformatted the document on the way out, which
 * re-indented the signature and made the authority refuse every simplified
 * document for weeks; the other never did. Fixing one taught nobody anything
 * about the other, which is the argument for there being one.
 *
 * The contract is deliberately string to string rather than a mutation of a
 * caller's document, because the load and the save are where the danger is and
 * a caller should not be choosing them. This runs *after* the document is
 * signed:
 *
 *   Whitespace is preserved on the way in. Loading with it stripped rewrites
 *   every line, and ZATCA recomputing the invoice hash from what it received
 *   got a different answer from the one in QR tag 6.
 *
 *   Nothing is pretty-printed on the way out. formatOutput re-indents the
 *   signature - the xades:SignedProperties block grew from 726 bytes to 1130 -
 *   so the digest recorded in ds:SignedInfo described a block the document no
 *   longer carried. Standard documents cleared throughout, because ZATCA
 *   stamps those itself and only verifies the seller's signature on a
 *   simplified one, which is reported after the customer already holds it.
 *
 * `EmittedSignatureTest` and `EmittedDigestsTest` hold both properties, from
 * this side and from the production path respectively.
 *
 * One more thing worth not rediscovering, inherited from the copy this
 * replaced: for a long time the production side only ever *updated* an
 * existing node. XmlBuilder deliberately emits none - an empty QR trips
 * BR-CL-KSA-14, so it leaves the element out and expects it to be added once
 * the signature exists. The query therefore matched nothing, the method
 * returned quietly, and no invoice this platform produced carried a QR at all:
 * BR-KSA-27 for every simplified document, which is the one kind that cannot
 * do without it. The QR is what the customer scans, and a B2C invoice is not
 * verifiable without one. Hence inserting, not only updating - and hence
 * `EmittedSignatureTest` asserting that a QR is actually present, so this
 * cannot quietly become a no-op again.
 */
class QrCodeInjector
{
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * Return the document carrying this QR, byte-identical everywhere else.
     *
     * Called twice per document in the onboarding flow: once with an empty
     * value to settle the shape before the invoice hash is taken, then again
     * with the real QR - which is why replacing an existing value has to work
     * as well as inserting a new element.
     */
    public function inject(string $signedXml, string $qrCode): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        Xml::load($dom, $signedXml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        $existing = $xpath->query(
            "//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject"
        );

        if ($existing->length > 0) {
            $existing->item(0)->nodeValue = $qrCode;

            return (string) $dom->saveXML();
        }

        $this->place($dom, $xpath, $this->reference($dom, $qrCode));

        return (string) $dom->saveXML();
    }

    /**
     * The QR reference itself, as BR-KSA-27 wants it.
     */
    private function reference(DOMDocument $dom, string $qrCode): DOMElement
    {
        $reference = $dom->createElementNS(self::CAC_NS, 'cac:AdditionalDocumentReference');
        $reference->appendChild($dom->createElementNS(self::CBC_NS, 'cbc:ID', 'QR'));

        $binary = $dom->createElementNS(self::CBC_NS, 'cbc:EmbeddedDocumentBinaryObject', $qrCode);
        $binary->setAttribute('mimeCode', 'text/plain');

        $attachment = $dom->createElementNS(self::CAC_NS, 'cac:Attachment');
        $attachment->appendChild($binary);
        $reference->appendChild($attachment);

        return $reference;
    }

    /**
     * Directly after PIH, because UBL is a sequence and position is validity.
     *
     * Inserted relative to PIH's own parent rather than the document element.
     * Both are the invoice root in a UBL document, so the two agree - but
     * insertBefore throws if the reference node is not a child of the node it
     * is called on, so asking the sibling where it lives cannot be wrong.
     */
    private function place(DOMDocument $dom, DOMXPath $xpath, DOMElement $reference): void
    {
        $pih = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='PIH']")->item(0);

        if ($pih === null) {
            $dom->documentElement?->appendChild($reference);

            return;
        }

        if ($pih->nextSibling !== null) {
            $pih->parentNode?->insertBefore($reference, $pih->nextSibling);

            return;
        }

        $pih->parentNode?->appendChild($reference);
    }
}
