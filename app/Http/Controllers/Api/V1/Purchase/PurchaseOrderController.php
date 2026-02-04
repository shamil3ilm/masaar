<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Resources\Purchase\PurchaseOrderResource;
use App\Models\Purchase\PurchaseOrder;
use App\Services\Purchase\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private PurchaseOrderService $purchaseOrderService
    ) {}

    /**
     * List purchase orders with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PurchaseOrder::with(['supplier', 'warehouse', 'lines'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->supplier_id, fn($q, $id) => $q->forSupplier($id))
            ->when($request->pending_receipt === 'true', fn($q) => $q->pendingReceipt())
            ->when($request->start_date, fn($q, $date) => $q->where('order_date', '>=', $date))
            ->when($request->end_date, fn($q, $date) => $q->where('order_date', '<=', $date))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('order_number', 'like', "%{$search}%")
                        ->orWhere('supplier_name', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->sort_by ?? 'order_date', $request->sort_order ?? 'desc');

        $orders = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return PurchaseOrderResource::collection($orders);
    }

    /**
     * Store a new purchase order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:contacts,id',
            'order_number' => 'nullable|string|max:50',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'branch_id' => 'nullable|exists:branches,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'delivery_address' => 'nullable|string|max:500',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'terms_and_conditions' => 'nullable|string',
            'reference' => 'nullable|string|max:100',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.variant_id' => 'nullable|exists:product_variants,id',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.tax_category_id' => 'nullable|exists:tax_categories,id',
            'lines.*.warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $order = $this->purchaseOrderService->create(
            collect($validated)->except('lines')->toArray(),
            $validated['lines']
        );

        return response()->json([
            'message' => 'Purchase order created successfully.',
            'data' => new PurchaseOrderResource($order),
        ], 201);
    }

    /**
     * Show a specific purchase order.
     */
    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return new PurchaseOrderResource(
            $purchaseOrder->load(['supplier', 'warehouse', 'lines.product', 'lines.variant', 'lines.taxCategory', 'bills'])
        );
    }

    /**
     * Update a draft purchase order.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $validated = $request->validate([
            'order_date' => 'sometimes|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'delivery_address' => 'nullable|string|max:500',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'terms_and_conditions' => 'nullable|string',
            'reference' => 'nullable|string|max:100',
            'version' => 'sometimes|integer',
            'lines' => 'sometimes|array|min:1',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.variant_id' => 'nullable|exists:product_variants,id',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.tax_category_id' => 'nullable|exists:tax_categories,id',
            'lines.*.warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $order = $this->purchaseOrderService->update(
            $purchaseOrder,
            collect($validated)->except('lines')->toArray(),
            $validated['lines'] ?? null
        );

        return response()->json([
            'message' => 'Purchase order updated successfully.',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Delete a draft purchase order.
     */
    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        if (!$purchaseOrder->isEditable()) {
            return response()->json([
                'message' => 'Only draft/sent orders can be deleted.',
            ], 422);
        }

        $purchaseOrder->lines()->delete();
        $purchaseOrder->delete();

        return response()->json([
            'message' => 'Purchase order deleted successfully.',
        ]);
    }

    /**
     * Send purchase order to supplier.
     */
    public function send(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->purchaseOrderService->send($purchaseOrder);

        return response()->json([
            'message' => 'Purchase order sent successfully.',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Confirm a purchase order.
     */
    public function confirm(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->purchaseOrderService->confirm($purchaseOrder);

        return response()->json([
            'message' => 'Purchase order confirmed successfully.',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Cancel a purchase order.
     */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $order = $this->purchaseOrderService->cancel($purchaseOrder, $validated['reason'] ?? '');

        return response()->json([
            'message' => 'Purchase order cancelled successfully.',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Receive items against purchase order.
     */
    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $validated = $request->validate([
            'line_quantities' => 'required|array',
            'line_quantities.*' => 'required|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $order = $this->purchaseOrderService->receive(
            $purchaseOrder,
            $validated['line_quantities'],
            $validated['warehouse_id'] ?? null
        );

        return response()->json([
            'message' => 'Items received successfully.',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Duplicate a purchase order.
     */
    public function duplicate(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $newOrder = $this->purchaseOrderService->duplicate($purchaseOrder);

        return response()->json([
            'message' => 'Purchase order duplicated successfully.',
            'data' => new PurchaseOrderResource($newOrder),
        ], 201);
    }

    /**
     * Get purchase orders summary/stats.
     */
    public function summary(Request $request): JsonResponse
    {
        $summary = $this->purchaseOrderService->getSummary(
            $request->supplier_id ? (int) $request->supplier_id : null
        );

        return response()->json(['data' => $summary]);
    }
}
