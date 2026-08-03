<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Http\Controllers;

use App\Domains\Invoice\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Domains\Pipeline\Http\Requests\PipelineSubmitRequest;
use App\Http\Responses\ApiResponse;
use App\Domains\Pipeline\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pipeline API controller for atomic ERP-ZATCA integration.
 *
 * Provides a single endpoint that combines invoice creation,
 * compliance data generation, and ZATCA submission into one
 * atomic HTTP request.
 */
class PipelineController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipelineService,
    ) {}

    /**
     * Submit an invoice through the full pipeline.
     *
     * POST /api/v1/pipeline/submit
     *
     * Creates the invoice, generates compliance data (hash, QR, signed XML),
     * and optionally submits to ZATCA government API in a single request.
     *
     */
    public function submit(PipelineSubmitRequest $request): JsonResponse
    {
        $data = $request->validated();

        $organizationId = $data['organization_id'];
        $branchId = $data['branch_id'] ?? null;

        // Security: ensure the request org matches the authenticated API key's org
        $authenticatedOrgId = $request->attributes->get('organization_id');
        if ($authenticatedOrgId !== null && $authenticatedOrgId !== $organizationId) {
            return ApiResponse::forbidden(
                'organization_id does not match the authenticated API key\'s organization.'
            );
        }

        $result = $this->pipelineService->submitInvoice(
            data: $data,
            organizationId: $organizationId,
            branchId: $branchId,
        );

        $hasErrors = !empty($result['errors']);
        $status = $result['status'];

        // Determine HTTP status code based on outcome
        if ($hasErrors && in_array($status, ['draft'], true)) {
            // Invoice created but compliance generation failed
            return ApiResponse::success(
                data: $result,
                message: 'Invoice created but compliance generation failed. See errors for details.',
                status: 207,
            );
        }

        if ($hasErrors && in_array($status, ['issued', 'rejected'], true)) {
            // Invoice issued but ZATCA submission had issues
            return ApiResponse::success(
                data: $result,
                message: 'Invoice processed with warnings or errors. See errors for details.',
                status: 207,
            );
        }

        return ApiResponse::created(
            data: $result,
            message: 'Invoice submitted successfully through pipeline',
        );
    }

    /**
     * Get pipeline status for an invoice.
     *
     * GET /api/v1/pipeline/status/{invoiceId}
     */
    public function status(Request $request, string $invoiceId): JsonResponse
    {
        $authenticatedOrgId = $request->attributes->get('organization_id');

        if ($authenticatedOrgId === null) {
            return ApiResponse::error('Organization context is required.', 401);
        }

        $invoice = Invoice::with('lines')
            ->where('organization_id', $authenticatedOrgId)
            ->findOrFail($invoiceId);

        return ApiResponse::success([
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->uuid ?? $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'type' => $invoice->type->value,
            'hash' => $invoice->hash,
            'qr_code' => $invoice->qr_code,
            'signed_xml' => $invoice->signed_xml,
            'zatca_response' => $invoice->zatca_response,
            'totals' => [
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
            ],
            'issue_date' => $invoice->issue_date?->toDateString(),
            'created_at' => $invoice->created_at?->toIso8601String(),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
        ], 'Invoice status retrieved');
    }
}
