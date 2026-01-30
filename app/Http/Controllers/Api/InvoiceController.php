<?php

namespace App\Http\Controllers\Api;

use App\Audits\AuditService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvoiceRequest;
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
    ) {}

    /**
     * List invoices for current organization.
     *
     * GET /api/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::where('organization_id', $this->tenant->getOrganizationId());

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
        $invoice = Invoice::create([
            'organization_id' => $this->tenant->getOrganizationId(),
            'invoice_number' => $request->invoice_number,
            'type' => $request->type,
            'document_type' => $request->document_type,
            'status' => InvoiceStatus::Draft,
            'issue_date' => $request->issue_date,
            'supply_date' => $request->supply_date,
            'currency' => $request->currency ?? 'SAR',
            'payment_means_code' => $request->payment_means_code ?? '10',
            'buyer_name' => $request->buyer_name,
            'buyer_vat_number' => $request->buyer_vat_number,
            'buyer_address' => $request->buyer_address,
            'billing_reference_id' => $request->billing_reference_id,
            'adjustment_reason' => $request->adjustment_reason,
            'notes' => $request->notes,
        ]);

        // Create invoice lines and calculate totals
        $subtotal = 0;
        $taxAmount = 0;
        $discountAmount = (float) ($request->discount_amount ?? 0);

        foreach ($request->lines as $line) {
            $lineSubtotal = $line['quantity'] * $line['unit_price'];
            $taxRate = $line['tax_rate'] ?? 15;
            $lineTax = $lineSubtotal * $taxRate / 100;

            $invoice->lines()->create([
                'description' => $line['description'],
                'item_classification_code' => $line['item_classification_code'] ?? null,
                'quantity' => $line['quantity'],
                'unit_code' => $line['unit_code'] ?? 'PCE',
                'unit_price' => $line['unit_price'],
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'tax_category' => $line['tax_category'] ?? 'S',
                'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
                'tax_exemption_reason' => $line['tax_exemption_reason'] ?? null,
                'line_total' => $lineSubtotal + $lineTax,
            ]);

            $subtotal += $lineSubtotal;
            $taxAmount += $lineTax;
        }

        // Calculate final totals with discount
        $invoice->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total' => $subtotal - $discountAmount + $taxAmount,
        ]);

        $this->audit->logCreated($invoice);

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
        $invoice = Invoice::where('organization_id', $this->tenant->getOrganizationId())
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
        $invoice = Invoice::where('organization_id', $this->tenant->getOrganizationId())
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
        $invoice = Invoice::where('organization_id', $this->tenant->getOrganizationId())
            ->findOrFail($id);

        if (! $invoice->isEditable()) {
            return ApiResponse::error('Cannot delete issued invoice', 422);
        }

        $this->audit->logDeleted($invoice);
        $invoice->delete();

        return ApiResponse::success(null, 'Invoice deleted');
    }
}
