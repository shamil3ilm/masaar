<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Client\ZatcaClient;
use App\Domains\Compliance\Zatca\DTOs\ZatcaResponse;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * ZATCA submission service.
 *
 * Orchestrates the full invoice submission workflow:
 * - Generate compliance data
 * - Choose clearance vs reporting
 * - Submit to ZATCA
 * - Update invoice state
 *
 * This is the single entry point for ZATCA submissions.
 */
class ZatcaSubmissionService
{
    public function __construct(
        private readonly ZatcaComplianceService $compliance,
        private readonly ZatcaClient $client,
    ) {}

    /**
     * Generate compliance data and issue invoice.
     *
     * @return array{hash: string, qr_code: string}
     */
    public function generate(Invoice $invoice, Organization $organization): array
    {
        $previousHash = $this->getPreviousInvoiceHash($invoice);

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            sellerName: $organization->name,
            sellerVatNumber: $organization->compliance_profile['vat_number'] ?? '',
            previousInvoiceHash: $previousHash,
        );

        // Update invoice with compliance data
        $invoice->update([
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
            'status' => InvoiceStatus::Issued,
        ]);

        return [
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
        ];
    }

    /**
     * Validate invoice with ZATCA (without submission).
     */
    public function validate(Invoice $invoice, Organization $organization): ZatcaResponse
    {
        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            sellerName: $organization->name,
            sellerVatNumber: $organization->compliance_profile['vat_number'] ?? '',
        );

        return $this->client->checkCompliance(
            invoiceXml: $complianceData['xml'],
            invoiceHash: $complianceData['hash'],
            uuid: $invoice->id,
        );
    }

    /**
     * Submit invoice to ZATCA (clearance or reporting).
     */
    public function submit(Invoice $invoice, Organization $organization): ZatcaResponse
    {
        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            sellerName: $organization->name,
            sellerVatNumber: $organization->compliance_profile['vat_number'] ?? '',
        );

        // Choose clearance (B2B) or reporting (B2C) based on invoice type
        $response = $invoice->requiresClearance()
            ? $this->client->clearInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            )
            : $this->client->reportInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            );

        // Update invoice status based on response
        $this->updateInvoiceStatus($invoice, $response);

        return $response;
    }

    /**
     * Update invoice status after ZATCA response.
     */
    private function updateInvoiceStatus(Invoice $invoice, ZatcaResponse $response): void
    {
        $invoice->update([
            'status' => $response->success ? InvoiceStatus::Accepted : InvoiceStatus::Rejected,
            'zatca_response' => [
                'clearance_status' => $response->clearanceStatus,
                'reporting_status' => $response->reportingStatus,
                'validation_status' => $response->validationStatus,
                'warnings' => $response->warningMessages,
                'errors' => $response->errorMessages,
            ],
        ]);
    }

    /**
     * Get hash of previous invoice for chaining.
     */
    private function getPreviousInvoiceHash(Invoice $invoice): ?string
    {
        $previous = Invoice::where('organization_id', $invoice->organization_id)
            ->where('created_at', '<', $invoice->created_at)
            ->whereNotNull('hash')
            ->orderBy('created_at', 'desc')
            ->first();

        return $previous?->hash;
    }
}
