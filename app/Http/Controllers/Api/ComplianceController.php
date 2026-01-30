<?php

namespace App\Http\Controllers\Api;

use App\Domains\Compliance\Zatca\Client\ZatcaClient;
use App\Domains\Compliance\Zatca\Services\ZatcaComplianceService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * ZATCA Compliance API controller.
 */
class ComplianceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ZatcaComplianceService $compliance,
        private readonly ZatcaClient $zatcaClient,
    ) {}

    /**
     * Generate compliance data for invoice (hash, QR, XML).
     *
     * POST /api/compliance/zatca/generate/{invoiceId}
     */
    public function generate(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);
        $organization = $this->tenant->getOrganization();

        // Get previous invoice hash for chaining
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

        return response()->json([
            'message' => 'Compliance data generated',
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
        ]);
    }

    /**
     * Validate invoice with ZATCA (without submission).
     *
     * POST /api/compliance/zatca/validate/{invoiceId}
     */
    public function validate(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);
        $organization = $this->tenant->getOrganization();

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            sellerName: $organization->name,
            sellerVatNumber: $organization->compliance_profile['vat_number'] ?? '',
        );

        $response = $this->zatcaClient->checkCompliance(
            invoiceXml: $complianceData['xml'],
            invoiceHash: $complianceData['hash'],
            uuid: $invoice->id,
        );

        return response()->json([
            'valid' => $response->success,
            'status' => $response->validationStatus,
            'warnings' => $response->warningMessages,
            'errors' => $response->errorMessages,
        ]);
    }

    /**
     * Submit invoice to ZATCA.
     *
     * POST /api/compliance/zatca/submit/{invoiceId}
     */
    public function submit(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);

        if ($invoice->status !== InvoiceStatus::Issued) {
            return response()->json([
                'error' => 'Invoice must be issued before submission',
            ], 422);
        }

        $organization = $this->tenant->getOrganization();

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            sellerName: $organization->name,
            sellerVatNumber: $organization->compliance_profile['vat_number'] ?? '',
        );

        // Choose clearance or reporting based on invoice type
        $response = $invoice->requiresClearance()
            ? $this->zatcaClient->clearInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            )
            : $this->zatcaClient->reportInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            );

        // Update invoice status
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

        return response()->json([
            'success' => $response->success,
            'status' => $response->clearanceStatus ?? $response->reportingStatus,
            'warnings' => $response->warningMessages,
            'errors' => $response->errorMessages,
        ]);
    }

    /**
     * Get invoice status.
     *
     * GET /api/compliance/zatca/status/{invoiceId}
     */
    public function status(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);

        return response()->json([
            'invoice_id' => $invoice->id,
            'status' => $invoice->status->value,
            'hash' => $invoice->hash,
            'qr_code' => $invoice->qr_code,
            'zatca_response' => $invoice->zatca_response,
        ]);
    }

    /**
     * Get invoice scoped to current organization.
     */
    private function getInvoice(string $id): Invoice
    {
        return Invoice::where('organization_id', $this->tenant->getOrganizationId())
            ->with('lines')
            ->findOrFail($id);
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
