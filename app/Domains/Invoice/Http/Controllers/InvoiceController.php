<?php

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invoice API controller.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly AuditService $audit,
        private readonly InvoiceDrafter $drafter,
    ) {}

    /**
     * List invoices for current organization.
     *
     * GET /api/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::where('org_id', $this->tenant->getOrganizationId());

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return ApiResponse::paginated($invoices);
    }

    /**
     * Create a new invoice.
     *
     * POST /api/invoices
     */
    public function store(CreateInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->drafter->draft($request->validated(), $this->tenant->getOrganizationId());

        return ApiResponse::created([
            'invoice' => $invoice->load('lines'),
        ], 'Invoice created');
    }

    /**
     * Get single invoice.
     *
     * GET /api/invoices/{id}
     */
    public function show(string $id): JsonResponse
    {
        $invoice = Invoice::where('org_id', $this->tenant->getOrganizationId())
            ->with('lines')
            ->findOrFail($id);

        return ApiResponse::success(['invoice' => $invoice]);
    }

    /**
     * Update draft invoice.
     *
     * PUT /api/invoices/{id}
     */
    public function update(CreateInvoiceRequest $request, string $id): JsonResponse
    {
        $invoice = Invoice::where('org_id', $this->tenant->getOrganizationId())
            ->findOrFail($id);

        if (! $invoice->isEditable()) {
            return ApiResponse::error('Invoice cannot be edited after issuance', 422);
        }

        $oldValues = $invoice->toArray();

        $invoice->update($request->only([
            'invoice_number',
            'issue_date',
            'supply_date',
            'buyer_name',
            'buyer_vat_number',
            'buyer_address',
            'notes',
        ]));

        $this->audit->logUpdated($invoice, $oldValues);

        return ApiResponse::success([
            'invoice' => $invoice->fresh('lines'),
        ], 'Invoice updated');
    }

    /**
     * Delete draft invoice.
     *
     * DELETE /api/invoices/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $invoice = Invoice::where('org_id', $this->tenant->getOrganizationId())
            ->findOrFail($id);

        if (! $invoice->isEditable()) {
            return ApiResponse::error('Cannot delete issued invoice', 422);
        }

        $this->audit->logDeleted($invoice);
        $invoice->delete();

        return ApiResponse::success(null, 'Invoice deleted');
    }
}
