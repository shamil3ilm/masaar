<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Zatca\DTOs\QrCodeData;
use App\Domains\Invoice\Models\Invoice;

/**
 * Main ZATCA compliance service.
 *
 * Orchestrates XML generation, hashing, and QR code creation.
 * Use this service to prepare invoices for ZATCA submission.
 */
class ZatcaComplianceService
{
    public function __construct(
        private readonly XmlBuilder $xmlBuilder,
        private readonly InvoiceHasher $hasher,
        private readonly QrCodeGenerator $qrGenerator,
    ) {}

    /**
     * Generate compliance data for an invoice.
     *
     * @return array{xml: string, hash: string, qr_code: string}
     */
    public function generateComplianceData(
        Invoice $invoice,
        string $sellerName,
        string $sellerVatNumber,
        ?string $previousInvoiceHash = null,
    ): array {
        // Build XML data
        $xmlData = new InvoiceXmlData(
            uuid: $invoice->id,
            invoiceNumber: $invoice->invoice_number,
            issueDate: $invoice->issue_date->format('Y-m-d'),
            issueTime: $invoice->created_at->format('H:i:s'),
            invoiceTypeCode: $invoice->type->value === 'standard' ? '388' : '388',
            currency: $invoice->currency,
            sellerName: $sellerName,
            sellerVatNumber: $sellerVatNumber,
            buyerName: $invoice->buyer_name,
            buyerVatNumber: $invoice->buyer_vat_number,
            subtotal: (float) $invoice->subtotal,
            taxAmount: (float) $invoice->tax_amount,
            total: (float) $invoice->total,
            lines: $this->formatLines($invoice),
            previousInvoiceHash: $previousInvoiceHash,
        );

        // Generate XML
        $xml = $this->xmlBuilder->build($xmlData);

        // Generate hash
        $hash = $this->hasher->hash($xml);

        // Generate QR code
        $qrData = QrCodeData::fromInvoice(
            sellerName: $sellerName,
            vatNumber: $sellerVatNumber,
            timestamp: $invoice->issue_date,
            total: (float) $invoice->total,
            vatAmount: (float) $invoice->tax_amount,
            hash: $hash,
        );

        $qrCode = $invoice->requiresClearance()
            ? $this->qrGenerator->generatePhase2($qrData)
            : $this->qrGenerator->generatePhase1($qrData);

        return [
            'xml' => $xml,
            'hash' => $hash,
            'qr_code' => $qrCode,
        ];
    }

    /**
     * Format invoice lines for XML.
     */
    private function formatLines(Invoice $invoice): array
    {
        return $invoice->lines->map(fn ($line) => [
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'unitPrice' => (float) $line->unit_price,
            'lineTotal' => (float) $line->line_total,
        ])->toArray();
    }
}
