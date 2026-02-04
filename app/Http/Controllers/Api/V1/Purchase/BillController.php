<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Resources\Purchase\BillResource;
use App\Models\Purchase\Bill;
use App\Services\Purchase\BillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillController extends Controller
{
    public function __construct(
        private BillService $billService
    ) {}

    /**
     * List bills with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Bill::with(['supplier', 'lines', 'purchaseOrder'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->supplier_id, fn($q, $id) => $q->forSupplier($id))
            ->when($request->bill_type, fn($q, $type) => $q->where('bill_type', $type))
            ->when($request->overdue === 'true', fn($q) => $q->overdue())
            ->when($request->start_date, fn($q, $date) => $q->where('bill_date', '>=', $date))
            ->when($request->end_date, fn($q, $date) => $q->where('bill_date', '<=', $date))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('bill_number', 'like', "%{$search}%")
                        ->orWhere('supplier_name', 'like', "%{$search}%")
                        ->orWhere('supplier_invoice_number', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->sort_by ?? 'bill_date', $request->sort_order ?? 'desc');

        $bills = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return BillResource::collection($bills);
    }

    /**
     * Store a new bill.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:contacts,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'bill_number' => 'nullable|string|max:50',
            'supplier_invoice_number' => 'nullable|string|max:100',
            'bill_type' => 'nullable|in:standard,debit_note,credit_note',
            'bill_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:bill_date',
            'received_date' => 'nullable|date',
            'branch_id' => 'nullable|exists:branches,id',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'place_of_supply' => 'nullable|string|max:50',
            'is_reverse_charge' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.tax_category_id' => 'nullable|exists:tax_categories,id',
            'lines.*.account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $bill = $this->billService->create(
            collect($validated)->except('lines')->toArray(),
            $validated['lines']
        );

        return response()->json([
            'message' => 'Bill created successfully.',
            'data' => new BillResource($bill),
        ], 201);
    }

    /**
     * Show a specific bill.
     */
    public function show(Bill $bill): BillResource
    {
        return new BillResource(
            $bill->load(['supplier', 'lines.product', 'lines.taxCategory', 'purchaseOrder', 'journalEntry.lines', 'paymentAllocations.payment'])
        );
    }

    /**
     * Update a draft bill.
     */
    public function update(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'supplier_invoice_number' => 'nullable|string|max:100',
            'bill_date' => 'sometimes|date',
            'due_date' => 'nullable|date|after_or_equal:bill_date',
            'received_date' => 'nullable|date',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'place_of_supply' => 'nullable|string|max:50',
            'is_reverse_charge' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'version' => 'sometimes|integer',
            'lines' => 'sometimes|array|min:1',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.tax_category_id' => 'nullable|exists:tax_categories,id',
            'lines.*.account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $bill = $this->billService->update(
            $bill,
            collect($validated)->except('lines')->toArray(),
            $validated['lines'] ?? null
        );

        return response()->json([
            'message' => 'Bill updated successfully.',
            'data' => new BillResource($bill),
        ]);
    }

    /**
     * Delete a draft bill.
     */
    public function destroy(Bill $bill): JsonResponse
    {
        if (!$bill->isEditable()) {
            return response()->json([
                'message' => 'Only draft/pending bills can be deleted.',
            ], 422);
        }

        $bill->lines()->delete();
        $bill->delete();

        return response()->json([
            'message' => 'Bill deleted successfully.',
        ]);
    }

    /**
     * Approve a bill.
     */
    public function approve(Bill $bill): JsonResponse
    {
        $bill = $this->billService->approve($bill);

        return response()->json([
            'message' => 'Bill approved successfully.',
            'data' => new BillResource($bill),
        ]);
    }

    /**
     * Void a bill.
     */
    public function void(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $bill = $this->billService->void($bill, $validated['reason'] ?? '');

        return response()->json([
            'message' => 'Bill voided successfully.',
            'data' => new BillResource($bill),
        ]);
    }

    /**
     * Create bill from purchase order.
     */
    public function createFromPurchaseOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'line_quantities' => 'nullable|array',
            'line_quantities.*' => 'numeric|min:0',
        ]);

        $order = \App\Models\Purchase\PurchaseOrder::findOrFail($validated['purchase_order_id']);

        $bill = $this->billService->createFromPurchaseOrder(
            $order,
            $validated['line_quantities'] ?? null
        );

        return response()->json([
            'message' => 'Bill created from purchase order successfully.',
            'data' => new BillResource($bill),
        ], 201);
    }

    /**
     * Get bills summary/stats.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = Bill::query();

        if ($request->supplier_id) {
            $query->forSupplier($request->supplier_id);
        }

        $draft = (clone $query)->draft()->count();
        $unpaid = (clone $query)->unpaid()->count();
        $overdue = (clone $query)->overdue()->count();

        $unpaidValue = (clone $query)->unpaid()->sum('amount_due');
        $overdueValue = (clone $query)->overdue()->sum('amount_due');

        return response()->json([
            'data' => [
                'total_count' => $query->count(),
                'draft_count' => $draft,
                'unpaid_count' => $unpaid,
                'overdue_count' => $overdue,
                'unpaid_value' => (float) $unpaidValue,
                'overdue_value' => (float) $overdueValue,
            ],
        ]);
    }
}
