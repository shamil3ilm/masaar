<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\InvoiceResource;
use App\Models\Sales\Invoice;
use App\Services\Compliance\CompliPayClient;
use App\Services\Sales\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private CompliPayClient $compliPayClient
    ) {}

    /**
     * List invoices with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Invoice::with(['customer', 'salesperson'])
            ->latest('invoice_date');

        if ($request->has('customer_id')) {
            $query->forCustomer($request->integer('customer_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('type')) {
            $query->ofType($request->input('type'));
        }

        if ($request->has('from_date')) {
            $query->where('invoice_date', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('invoice_date', '<=', $request->input('to_date'));
        }

        if ($request->boolean('overdue', false)) {
            $query->overdue();
        }

        if ($request->boolean('unpaid', false)) {
            $query->unpaid();
        }

        $invoices = $query->paginate($request->integer('per_page', 15));

        return InvoiceResource::collection($invoices);
    }

    /**
     * Create a new invoice.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_type' => 'nullable|in:standard,simplified,credit_note,debit_note',
            'customer_id' => 'required|integer|exists:contacts,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'place_of_supply' => 'nullable|string|max:2',
            'salesperson_id' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|string|max:2000',
            'terms_and_conditions' => 'nullable|string|max:5000',
            'reference' => 'nullable|string|max:100',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_id' => 'nullable|integer|exists:units_of_measure,id',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.tax_category_id' => 'nullable|integer|exists:tax_categories,id',
            'lines.*.account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'lines.*.warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'lines.*.hsn_code' => 'nullable|string|max:20',
        ]);

        $invoice = $this->invoiceService->create(
            collect($validated)->except('lines')->toArray(),
            $validated['lines']
        );

        return response()->json([
            'success' => true,
            'message' => 'Invoice created successfully.',
            'data' => new InvoiceResource($invoice),
        ], 201);
    }

    /**
     * Show an invoice.
     */
    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load([
            'customer',
            'lines.product',
            'lines.variant',
            'salesperson',
            'journalEntry.lines',
            'paymentAllocations.payment',
        ]);

        return response()->json([
            'success' => true,
            'data' => new InvoiceResource($invoice),
        ]);
    }

    /**
     * Update a draft invoice.
     */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'required|integer',
            'customer_id' => 'sometimes|integer|exists:contacts,id',
            'invoice_date' => 'sometimes|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'place_of_supply' => 'nullable|string|max:2',
            'salesperson_id' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|string|max:2000',
            'terms_and_conditions' => 'nullable|string|max:5000',
            'reference' => 'nullable|string|max:100',
            'lines' => 'nullable|array|min:1',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_id' => 'nullable|integer|exists:units_of_measure,id',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.tax_category_id' => 'nullable|integer|exists:tax_categories,id',
            'lines.*.account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'lines.*.warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'lines.*.hsn_code' => 'nullable|string|max:20',
        ]);

        $invoice = $this->invoiceService->update(
            $invoice,
            collect($validated)->except('lines')->toArray(),
            $validated['lines'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Invoice updated successfully.',
            'data' => new InvoiceResource($invoice),
        ]);
    }

    /**
     * Send/post an invoice.
     */
    public function send(Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoiceService->send($invoice);

        return response()->json([
            'success' => true,
            'message' => 'Invoice sent successfully.',
            'data' => new InvoiceResource($invoice),
        ]);
    }

    /**
     * Void an invoice.
     */
    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $invoice = $this->invoiceService->void($invoice, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Invoice voided successfully.',
            'data' => new InvoiceResource($invoice),
        ]);
    }

    /**
     * Create a credit note.
     */
    public function createCreditNote(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_category_id' => 'nullable|integer|exists:tax_categories,id',
        ]);

        $creditNote = $this->invoiceService->createCreditNote(
            $invoice,
            $validated['lines'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Credit note created successfully.',
            'data' => new InvoiceResource($creditNote),
        ], 201);
    }

    /**
     * Get compliance status.
     */
    public function complianceStatus(Invoice $invoice): JsonResponse
    {
        if (!$invoice->compliance_uuid) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $invoice->compliance_status,
                    'message' => 'Not submitted to compliance system.',
                ],
            ]);
        }

        $result = $this->compliPayClient->getStatus($invoice->compliance_uuid);

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $result->status,
                'uuid' => $invoice->compliance_uuid,
                'qr_code' => $invoice->compliance_qr_code,
                'submitted_at' => $invoice->compliance_submitted_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Delete a draft invoice.
     */
    public function destroy(Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Only draft invoices can be deleted.',
                ],
            ], 422);
        }

        $invoice->delete();

        return response()->json([
            'success' => true,
            'message' => 'Invoice deleted successfully.',
        ]);
    }

    /**
     * Get invoice summary/stats.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = Invoice::query();

        if ($request->has('from_date')) {
            $query->where('invoice_date', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('invoice_date', '<=', $request->input('to_date'));
        }

        $stats = [
            'total_invoices' => $query->count(),
            'total_amount' => $query->sum('total'),
            'total_paid' => $query->sum('amount_paid'),
            'total_outstanding' => $query->sum('amount_due'),
            'by_status' => Invoice::selectRaw('status, COUNT(*) as count, SUM(total) as total')
                ->groupBy('status')
                ->get()
                ->keyBy('status'),
            'overdue_count' => Invoice::overdue()->count(),
            'overdue_amount' => Invoice::overdue()->sum('amount_due'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
