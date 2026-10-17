<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Document;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use DOMDocument;
use DOMElement;

/**
 * ZATCA invoice XML builder.
 *
 * Generates UBL 2.1 compliant XML for ZATCA e-invoicing Phase 2.
 * Implements all mandatory fields per ZATCA specifications.
 *
 * The document is written in parts, each by its own builder, in the order
 * UBL fixes: header, parties, terms, totals, lines. This class owns the
 * document itself and the order the parts go in.
 */
class XmlBuilder
{
    private const UBL_NS = Ubl::INVOICE_NS;

    private const CAC_NS = Ubl::CAC_NS;

    private const CBC_NS = Ubl::CBC_NS;

    private const EXT_NS = Ubl::EXT_NS;

    /**
     * The enveloped-XAdES signature ZATCA expects, named in BR-KSA-30.
     */
    public const SIGNATURE_URI = 'urn:oasis:names:specification:ubl:dsig:enveloped:xades';

    /**
     * The one signature a ZATCA invoice carries, named in BR-KSA-28.
     */
    public const SIGNATURE_ID = 'urn:oasis:names:specification:ubl:signature:1';

    /**
     * What that signature refers to, per ZATCA's own samples.
     */
    public const SIGNATURE_REFERENCE = 'urn:oasis:names:specification:ubl:signature:Invoice';

    private DOMDocument $dom;

    private DOMElement $root;

    public function __construct(
        private readonly UblHeaderBuilder $header,
        private readonly UblPartyBuilder $parties,
        private readonly UblTotalsBuilder $totals,
        private readonly UblLineBuilder $lines,
    ) {}

    /**
     * Build complete invoice XML.
     */
    public function build(InvoiceXmlData $data): string
    {
        $this->initDocument();

        // ZATCA reads the signature from UBLExtensions, so the scaffold has to
        // exist before the signer runs — without somewhere to put it, the
        // signature lands under the invoice root where no verifier looks.
        //
        // Called before the content elements deliberately: the XPath transform
        // strips UBLExtensions before the document digest is taken, so the
        // scaffold does not change the invoice hash.
        $this->addSignatureExtension();

        $this->header->addHeader($this->dom, $data);
        $this->parties->addParties($this->dom, $data);
        $this->header->addTerms($this->dom, $data);
        $this->totals->addTotals($this->dom, $data);
        $this->lines->addLines($this->dom, $data);

        $this->dom->formatOutput = true;

        return $this->dom->saveXML();
    }

    /**
     * Initialize XML document with namespaces.
     */
    private function initDocument(): void
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');

        $this->root = $this->dom->createElementNS(self::UBL_NS, 'Invoice');
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC_NS);
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC_NS);
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::EXT_NS);

        $this->dom->appendChild($this->root);
    }

    /**
     * Add UBLExtensions placeholder for signature.
     */
    public function addSignatureExtension(): void
    {
        $extensions = $this->dom->createElementNS(self::EXT_NS, 'ext:UBLExtensions');
        $extension = $this->dom->createElementNS(self::EXT_NS, 'ext:UBLExtension');

        // BR-KSA-30 requires this exact URI. It is what identifies the
        // extension as the cryptographic stamp rather than any other kind.
        $extension->appendChild(
            Ubl::element($this->dom, self::EXT_NS, 'ext:ExtensionURI', self::SIGNATURE_URI)
        );

        $content = $this->dom->createElementNS(self::EXT_NS, 'ext:ExtensionContent');

        // Signature placeholder - will be filled by XAdES signer
        $sigPlaceholder = $this->dom->createComment('SIGNATURE_PLACEHOLDER');
        $content->appendChild($sigPlaceholder);

        $extension->appendChild($content);
        $extensions->appendChild($extension);

        // Insert at the beginning
        $this->root->insertBefore($extensions, $this->root->firstChild);
    }

    /**
     * Set QR code in XML (creates element if not exists).
     */
    public function setQrCode(string $qrCode): void
    {
        $xpath = new \DOMXPath($this->dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        $nodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject");

        if ($nodes->length > 0) {
            $nodes->item(0)->nodeValue = $qrCode;
        } else {
            // Create QR code element if it doesn't exist
            $this->addQrCodeElement($qrCode);
        }
    }

    /**
     * Add QR code AdditionalDocumentReference element.
     */
    private function addQrCodeElement(string $qrCode): void
    {
        // Find the PIH element to insert QR after it
        $xpath = new \DOMXPath($this->dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        $pihNodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='PIH']");

        $qr = $this->dom->createElementNS(self::CAC_NS, 'cac:AdditionalDocumentReference');
        $qr->appendChild(Ubl::element($this->dom, self::CBC_NS, 'cbc:ID', 'QR'));
        $qrAttachment = $this->dom->createElementNS(self::CAC_NS, 'cac:Attachment');
        $qrBinary = Ubl::element($this->dom, self::CBC_NS, 'cbc:EmbeddedDocumentBinaryObject', $qrCode);
        $qrBinary->setAttribute('mimeCode', 'text/plain');
        $qrAttachment->appendChild($qrBinary);
        $qr->appendChild($qrAttachment);

        if ($pihNodes->length > 0) {
            // Insert after PIH
            $pihNode = $pihNodes->item(0);
            if ($pihNode->nextSibling) {
                $this->root->insertBefore($qr, $pihNode->nextSibling);
            } else {
                $this->root->appendChild($qr);
            }
        } else {
            $this->root->appendChild($qr);
        }
    }
}
