<?php

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Services\InvoiceFinder;
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
    /**
     * Header fields a draft may change through the API.
     */
    private const REVISABLE = [
        'invoice_number',
        'issue_date',
        'supply_date',
        'buyer_name',
        'buyer_vat_number',
        'buyer_address',
        'notes',
    ];

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceDrafter $drafter,
        private readonly InvoiceFinder $invoices,
    ) {}

    /**
     * List invoices for current organization.
     *
     * GET /api/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = $this->invoices->paginate(
            $this->tenant->getOrganizationId(),
            $this->statusFilter($request),
            (int) $request->get('per_page', 15)
        );

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
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $id);

        return ApiResponse::success(['invoice' => $invoice]);
    }

    /**
     * Update draft invoice.
     *
     * PUT /api/invoices/{id}
     */
    public function update(CreateInvoiceRequest $request, string $id): JsonResponse
    {
        $invoice = $this->invoices->find($this->tenant->getOrganizationId(), $id);

        try {
            $invoice = $this->drafter->revise($invoice, $request->only(self::REVISABLE));
        } catch (InvoiceNotEditableException) {
            return ApiResponse::error('Invoice cannot be edited after issuance', 422);
        }

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
        $invoice = $this->invoices->find($this->tenant->getOrganizationId(), $id);

        try {
            $this->drafter->discard($invoice);
        } catch (InvoiceNotEditableException) {
            return ApiResponse::error('Cannot delete issued invoice', 422);
        }

        return ApiResponse::success(null, 'Invoice deleted');
    }

    /**
     * The status to list, or null for every status.
     *
     * A status parameter that is present filters even when blank, which then
     * matches nothing; one that is not a string is treated as blank.
     */
    private function statusFilter(Request $request): ?string
    {
        if (! $request->has('status')) {
            return null;
        }

        $status = $request->input('status');

        return is_string($status) ? $status : '';
    }
}
