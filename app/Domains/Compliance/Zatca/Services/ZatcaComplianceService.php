<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\DTOs\AddressData;
use App\Domains\Compliance\Zatca\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Zatca\DTOs\QrCodeData;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * Main ZATCA compliance service.
 *
 * Orchestrates XML generation, hashing, signing, and QR code creation.
 * This is the primary service for preparing invoices for ZATCA submission.
 */
class ZatcaComplianceService
{
    public function __construct(
        private readonly XmlBuilder $xmlBuilder,
        private readonly InvoiceHasher $hasher,
        private readonly QrCodeGenerator $qrGenerator,
        private readonly EcdsaSigner $ecdsaSigner,
        private readonly XadesSigner $xadesSigner,
        private readonly CertificateService $certificateService,
        private readonly ZatcaValidator $validator,
    ) {}

    /**
     * Generate complete compliance data for an invoice.
     *
     * @return array{xml: string, hash: string, qr_code: string, signed_xml: ?string}
     */
    public function generateComplianceData(
        Invoice $invoice,
        Organization $organization,
        ?string $previousInvoiceHash = null,
        ?string $privateKey = null,
        ?string $certificate = null,
    ): array {
        // Build XML data from invoice
        $xmlData = $this->buildXmlData($invoice, $organization, $previousInvoiceHash);

        // Generate unsigned XML
        $xml = $this->xmlBuilder->build($xmlData);

        // Generate invoice hash
        $hash = $this->hasher->hash($xml);

        // Determine if we can sign (have certificate)
        $canSign = $privateKey !== null && $certificate !== null;
        $signedXml = null;
        $signature = null;
        $publicKey = null;
        $certSignature = null;

        if ($canSign) {
            // Sign the XML
            $signedXml = $this->xadesSigner->sign($xml, $privateKey, $certificate);

            // Extract signature for QR code
            $signature = $this->xadesSigner->extractSignature($signedXml);

            // Get public key for QR
            $publicKey = $this->ecdsaSigner->getPublicKeyBytes(
                $this->ecdsaSigner->extractPublicKey($certificate)
            );

            // Get certificate signature for QR
            $certSignature = $this->certificateService->getCertificateSignature($certificate);

            // Update hash from signed XML
            $hash = $this->hasher->hash($signedXml);
        }

        // Generate QR code
        $qrData = new QrCodeData(
            sellerName: $organization->name,
            vatNumber: $organization->vat_number ?? '',
            timestamp: $invoice->issue_date->format('Y-m-d\TH:i:s\Z'),
            invoiceTotal: number_format((float) $invoice->total, 2, '.', ''),
            vatTotal: number_format((float) $invoice->tax_amount, 2, '.', ''),
            invoiceHash: $hash,
            signature: $signature,
            publicKey: $publicKey,
            certificateSignature: $certSignature,
        );

        // Generate QR based on invoice type (Phase 1 for B2C, Phase 2 for B2B)
        $qrCode = $invoice->requiresClearance()
            ? $this->qrGenerator->generatePhase2($qrData)
            : $this->qrGenerator->generatePhase1($qrData);

        // Update QR in XML if signed
        if ($signedXml !== null) {
            $dom = new \DOMDocument();
            $dom->loadXML($signedXml);
            $this->updateQrInXml($dom, $qrCode);
            $signedXml = $dom->saveXML();
        }

        return [
            'xml' => $canSign ? $signedXml : $xml,
            'hash' => $hash,
            'qr_code' => $qrCode,
            'signed_xml' => $signedXml,
        ];
    }

    /**
     * Validate invoice before submission.
     *
     * @return array{valid: bool, errors: array, warnings: array}
     */
    public function validateInvoice(Invoice $invoice, Organization $organization): array
    {
        return $this->validator->validate($invoice, $organization);
    }

    /**
     * Build XML data structure from invoice.
     */
    private function buildXmlData(
        Invoice $invoice,
        Organization $organization,
        ?string $previousInvoiceHash,
    ): InvoiceXmlData {
        // Get document type
        $documentType = $invoice->document_type ?? DocumentType::Invoice;

        // Get invoice subtype (01=B2B, 02=B2C)
        $invoiceSubtype = $invoice->type->value === 'standard' ? '01' : '02';

        // Format invoice lines
        $lines = $invoice->lines->map(fn ($line) => [
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'unitPrice' => (float) $line->unit_price,
            'taxRate' => (float) $line->tax_rate,
            'taxAmount' => (float) $line->tax_amount,
            'lineTotal' => (float) $line->line_total,
            'taxCategory' => $this->getTaxCategory($line->tax_rate),
            'unitCode' => 'PCE', // Default unit code
        ])->toArray();

        return new InvoiceXmlData(
            uuid: $invoice->id,
            invoiceNumber: $invoice->invoice_number,
            issueDate: $invoice->issue_date->format('Y-m-d'),
            issueTime: $invoice->created_at->format('H:i:s'),
            invoiceTypeCode: $documentType->getTypeCode(),
            invoiceSubtype: $invoiceSubtype,
            currency: $invoice->currency,
            sellerName: $organization->name,
            sellerVatNumber: $organization->vat_number ?? '',
            sellerAddress: $organization->getAddressData(),
            buyerName: $invoice->buyer_name,
            subtotal: (float) $invoice->subtotal,
            taxAmount: (float) $invoice->tax_amount,
            total: (float) $invoice->total,
            lines: $lines,
            supplyDate: $invoice->supply_date?->format('Y-m-d'),
            sellerCrNumber: $organization->cr_number,
            buyerVatNumber: $invoice->buyer_vat_number,
            buyerAddress: $invoice->buyer_address ? AddressData::fromArray($invoice->buyer_address) : null,
            paymentMeansCode: $invoice->payment_means_code ?? '10',
            previousInvoiceHash: $previousInvoiceHash,
            billingReferenceId: $invoice->billing_reference_id,
        );
    }

    /**
     * Get tax category code from rate.
     */
    private function getTaxCategory(float $taxRate): string
    {
        return match (true) {
            $taxRate === 15.0 => 'S',  // Standard rate
            $taxRate === 0.0 => 'Z',   // Zero-rated
            $taxRate === 5.0 => 'S',   // Reduced rate (treated as standard)
            default => 'S',
        };
    }

    /**
     * Update QR code in XML document.
     */
    private function updateQrInXml(\DOMDocument $dom, string $qrCode): void
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $nodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject");

        if ($nodes->length > 0) {
            $nodes->item(0)->nodeValue = $qrCode;
        }
    }
}
