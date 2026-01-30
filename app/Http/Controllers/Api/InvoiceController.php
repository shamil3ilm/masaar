<?php

namespace App\Http\Controllers\Api;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvoiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invoice API controller.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant
    ) {}

    /**
     * List invoices for current organization.
     *
     * GET /api/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::where('organization_id', $this->tenant->getOrganizationId());

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($invoices);
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
            'status' => InvoiceStatus::Draft,
            'issue_date' => $request->issue_date,
            'supply_date' => $request->supply_date,
            'currency' => $request->currency ?? 'SAR',
            'buyer_name' => $request->buyer_name,
            'buyer_vat_number' => $request->buyer_vat_number,
            'buyer_address' => $request->buyer_address,
            'notes' => $request->notes,
        ]);

        // Create invoice lines
        $subtotal = 0;
        $taxAmount = 0;

        foreach ($request->lines as $line) {
            $lineSubtotal = $line['quantity'] * $line['unit_price'];
            $lineTax = $lineSubtotal * ($line['tax_rate'] ?? 15) / 100;

            $invoice->lines()->create([
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'] ?? 15,
                'tax_amount' => $lineTax,
                'line_total' => $lineSubtotal + $lineTax,
            ]);

            $subtotal += $lineSubtotal;
            $taxAmount += $lineTax;
        }

        // Update totals
        $invoice->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $subtotal + $taxAmount,
        ]);

        return response()->json([
            'message' => 'Invoice created',
            'invoice' => $invoice->load('lines'),
        ], 201);
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

        return response()->json(['invoice' => $invoice]);
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
            return response()->json([
                'error' => 'Invoice cannot be edited after issuance',
            ], 422);
        }

        $invoice->update($request->only([
            'invoice_number',
            'issue_date',
            'supply_date',
            'buyer_name',
            'buyer_vat_number',
            'buyer_address',
            'notes',
        ]));

        return response()->json([
            'message' => 'Invoice updated',
            'invoice' => $invoice->fresh('lines'),
        ]);
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
            return response()->json([
                'error' => 'Cannot delete issued invoice',
            ], 422);
        }

        $invoice->delete();

        return response()->json([
            'message' => 'Invoice deleted',
        ]);
    }
}
