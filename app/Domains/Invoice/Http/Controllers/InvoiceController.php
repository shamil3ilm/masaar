<?php

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $invoice = DB::transaction(function () use ($request) {
            $invoice = Invoice::create([
                'org_id' => $this->tenant->getOrganizationId(),
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
                'billing_ref' => $request->billing_ref,
                'adjustment_reason' => $request->adjustment_reason,
                'notes' => $request->notes,
            ]);

            // Create invoice lines and calculate totals using bcmath to avoid
            // floating-point precision errors on monetary values (P1 fix).
            $subtotal = '0';
            $taxTotal = '0';
            $discountAmount = (string) ($request->discount_amount ?? '0');

            foreach ($request->lines as $line) {
                $quantity = (string) $line['quantity'];
                $unitPrice = (string) $line['unit_price'];
                $taxRate = (string) ($line['tax_rate'] ?? 15);

                $lineSubtotal = bcmul($quantity, $unitPrice, 2);
                $lineTax = bcdiv(bcmul($lineSubtotal, $taxRate, 4), '100', 2);
                $lineTotal = bcadd($lineSubtotal, $lineTax, 2);

                $invoice->lines()->create([
                    'description' => $line['description'],
                    'class_code' => $line['class_code'] ?? null,
                    'quantity' => $quantity,
                    'unit_code' => $line['unit_code'] ?? 'PCE',
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'tax_category' => $line['tax_category'] ?? 'S',
                    'exempt_code' => $line['exempt_code'] ?? null,
                    'exempt_reason' => $line['exempt_reason'] ?? null,
                    'line_total' => $lineTotal,
                ]);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $taxTotal = bcadd($taxTotal, $lineTax, 2);
            }

            // Calculate final totals with discount
            $total = bcadd(bcsub($subtotal, $discountAmount, 2), $taxTotal, 2);

            $invoice->update([
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxTotal,
                'total' => $total,
            ]);

            $this->audit->logCreated($invoice);

            return $invoice;
        });

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
