<?php

namespace App\Domains\Compliance\Fatoora\Http\Controllers;

use App\Domains\Compliance\Fatoora\Services\FatooraSubmissionService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Fatoora (KSA) Compliance API controller.
 *
 * Thin controller - delegates to FatooraSubmissionService.
 */
class ComplianceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly FatooraSubmissionService $submission,
    ) {}

    /**
     * Generate compliance data for invoice (hash, QR).
     *
     * POST /api/compliance/sa/generate/{invoiceId}
     */
    public function generate(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);
        $organization = $this->tenant->getOrganization();

        $result = $this->submission->generate($invoice, $organization);

        return ApiResponse::success($result, 'Compliance data generated');
    }

    /**
     * Validate invoice with Fatoora (without submission).
     *
     * POST /api/compliance/sa/validate/{invoiceId}
     */
    public function validate(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);
        $organization = $this->tenant->getOrganization();

        $response = $this->submission->validate($invoice, $organization);

        return ApiResponse::success([
            'valid' => $response->success,
            'status' => $response->validationStatus,
            'warnings' => $response->warningMessages,
            'errors' => $response->errorMessages,
        ]);
    }

    /**
     * Submit invoice to Fatoora.
     *
     * POST /api/compliance/sa/submit/{invoiceId}
     */
    public function submit(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);

        if ($invoice->status !== InvoiceStatus::Issued) {
            return ApiResponse::error('Invoice must be issued before submission', 422);
        }

        $organization = $this->tenant->getOrganization();

        $response = $this->submission->submit($invoice, $organization);

        if (! $response->success) {
            return ApiResponse::error(
                'Fatoora submission failed',
                422,
                $response->errorMessages
            );
        }

        return ApiResponse::success([
            'status' => $response->clearanceStatus ?? $response->reportingStatus,
            'warnings' => $response->warningMessages,
        ], 'Invoice submitted successfully');
    }

    /**
     * Get invoice status.
     *
     * GET /api/compliance/sa/status/{invoiceId}
     */
    public function status(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);

        return ApiResponse::success([
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
}
