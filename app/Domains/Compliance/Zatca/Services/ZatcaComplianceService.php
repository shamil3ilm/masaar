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
        // ZATCA TLV encoding requires raw bytes for tags 6-9, not base64
        // The services return base64 for storage/display, so we decode here
        $qrData = new QrCodeData(
            sellerName: $organization->name,
            vatNumber: $organization->vat_number ?? '',
            timestamp: $invoice->issue_date->format('Y-m-d\TH:i:s\Z'),
            invoiceTotal: number_format((float) $invoice->total, 2, '.', ''),
            vatTotal: number_format((float) $invoice->tax_amount, 2, '.', ''),
            // Tags 6-9: Decode base64 to raw bytes for TLV encoding
            invoiceHash: $hash !== null ? base64_decode($hash) : null,
            signature: $signature !== null ? base64_decode($signature) : null,
            publicKey: $publicKey !== null ? base64_decode($publicKey) : null,
            certificateSignature: $certSignature, // Already raw bytes
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

        // Format invoice lines with full tax category data from database
        $lines = $invoice->lines->map(fn ($line) => [
            'description' => $line->description,
            'itemClassificationCode' => $line->item_classification_code,
            'quantity' => (float) $line->quantity,
            'unitCode' => $line->unit_code ?? 'PCE',
            'unitPrice' => (float) $line->unit_price,
            'taxRate' => (float) $line->tax_rate,
            'taxAmount' => (float) $line->tax_amount,
            'lineTotal' => (float) $line->line_total,
            // Use stored tax category, fallback to computed value
            'taxCategory' => $line->tax_category ?? $this->getTaxCategory(
                (float) $line->tax_rate,
                $line->tax_exemption_code
            ),
            'taxExemptionReasonCode' => $line->tax_exemption_code,
            'taxExemptionReason' => $line->tax_exemption_reason,
        ])->toArray();

        return new InvoiceXmlData(
            uuid: $invoice->id,
            invoiceNumber: $invoice->invoice_number,
            icv: (int) $invoice->icv,
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
            discount: (float) ($invoice->discount_amount ?? 0),
            paymentMeansCode: $invoice->payment_means_code ?? '10',
            previousInvoiceHash: $previousInvoiceHash,
            billingReferenceId: $invoice->billing_reference_id,
            // Invoice type sub-flags (bits 3-7 per ZATCA specification)
            isThirdParty: (bool) ($invoice->is_third_party ?? false),
            isNominal: (bool) ($invoice->is_nominal ?? false),
            isExport: (bool) ($invoice->is_export ?? false),
            isSummary: (bool) ($invoice->is_summary ?? false),
            isSelfBilled: (bool) ($invoice->is_self_billed ?? false),
        );
    }

    /**
     * Get tax category code from rate.
     *
     * ZATCA Tax Categories:
     * - S = Standard rated (any positive VAT rate: 5%, 15%, etc.)
     * - Z = Zero rated (0% with exemption reason code)
     * - E = Exempt (not subject to VAT with exemption reason)
     * - O = Out of scope (services outside KSA)
     *
     * @param float $taxRate Tax rate percentage
     * @param string|null $exemptionCode Optional exemption reason code
     * @return string Tax category code
     */
    private function getTaxCategory(float $taxRate, ?string $exemptionCode = null): string
    {
        // If there's an exemption code, determine category from code
        if ($exemptionCode !== null) {
            return match (true) {
                str_starts_with($exemptionCode, 'VATEX-SA-') => 'E', // Exempt
                str_starts_with($exemptionCode, 'VATEX-SA-OOS') => 'O', // Out of scope
                str_starts_with($exemptionCode, 'VATEX-SA-HEA') => 'Z', // Zero-rated healthcare
                str_starts_with($exemptionCode, 'VATEX-SA-EDU') => 'Z', // Zero-rated education
                default => $taxRate > 0 ? 'S' : 'Z',
            };
        }

        // Determine from rate alone
        return match (true) {
            $taxRate > 0 => 'S',    // Standard rated (any positive rate)
            $taxRate === 0.0 => 'Z', // Zero-rated (default for 0%)
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
