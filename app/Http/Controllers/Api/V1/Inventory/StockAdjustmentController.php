<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\StockAdjustmentResource;
use App\Models\Inventory\StockAdjustment;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private StockAdjustmentService $adjustmentService
    ) {}

    /**
     * List stock adjustments.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockAdjustment::with(['warehouse', 'creator', 'poster'])
            ->latest();

        if ($request->has('warehouse_id')) {
            $query->inWarehouse($request->integer('warehouse_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('reason')) {
            $query->byReason($request->input('reason'));
        }

        if ($request->has('from_date')) {
            $query->where('adjustment_date', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('adjustment_date', '<=', $request->input('to_date'));
        }

        $adjustments = $query->paginate($request->integer('per_page', 15));

        return StockAdjustmentResource::collection($adjustments);
    }

    /**
     * Create a new stock adjustment.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'adjustment_date' => 'required|date',
            'reason' => 'required|in:damage,theft,expiry,count_correction,opening_balance,other',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.location_id' => 'nullable|integer|exists:warehouse_locations,id',
            'lines.*.actual_quantity' => 'required|numeric|min:0',
            'lines.*.notes' => 'nullable|string|max:255',
        ]);

        $adjustment = $this->adjustmentService->create(
            collect($validated)->except('lines')->toArray(),
            $validated['lines']
        );

        return response()->json([
            'success' => true,
            'message' => 'Stock adjustment created successfully.',
            'data' => new StockAdjustmentResource($adjustment),
        ], 201);
    }

    /**
     * Show a stock adjustment.
     */
    public function show(StockAdjustment $stockAdjustment): JsonResponse
    {
        $stockAdjustment->load(['warehouse', 'lines.product', 'lines.variant', 'creator', 'poster']);

        return response()->json([
            'success' => true,
            'data' => new StockAdjustmentResource($stockAdjustment),
        ]);
    }

    /**
     * Update a draft stock adjustment.
     */
    public function update(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        $validated = $request->validate([
            'adjustment_date' => 'sometimes|date',
            'reason' => 'sometimes|in:damage,theft,expiry,count_correction,opening_balance,other',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'nullable|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.location_id' => 'nullable|integer|exists:warehouse_locations,id',
            'lines.*.actual_quantity' => 'required|numeric|min:0',
            'lines.*.notes' => 'nullable|string|max:255',
        ]);

        $adjustment = $this->adjustmentService->update(
            $stockAdjustment,
            collect($validated)->except('lines')->toArray(),
            $validated['lines'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Stock adjustment updated successfully.',
            'data' => new StockAdjustmentResource($adjustment),
        ]);
    }

    /**
     * Post a stock adjustment.
     */
    public function post(StockAdjustment $stockAdjustment): JsonResponse
    {
        $adjustment = $this->adjustmentService->post($stockAdjustment);

        return response()->json([
            'success' => true,
            'message' => 'Stock adjustment posted successfully.',
            'data' => new StockAdjustmentResource($adjustment),
        ]);
    }

    /**
     * Cancel a draft stock adjustment.
     */
    public function cancel(StockAdjustment $stockAdjustment): JsonResponse
    {
        $adjustment = $this->adjustmentService->cancel($stockAdjustment);

        return response()->json([
            'success' => true,
            'message' => 'Stock adjustment cancelled.',
            'data' => new StockAdjustmentResource($adjustment),
        ]);
    }

    /**
     * Get adjustment summary.
     */
    public function summary(StockAdjustment $stockAdjustment): JsonResponse
    {
        $summary = $this->adjustmentService->getSummary($stockAdjustment);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Quick adjustment for a single product.
     */
    public function quickAdjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'actual_quantity' => 'required|numeric|min:0',
            'reason' => 'required|in:damage,theft,expiry,count_correction,opening_balance,other',
            'notes' => 'nullable|string|max:500',
        ]);

        $adjustment = $this->adjustmentService->quickAdjust(
            $validated['product_id'],
            $validated['warehouse_id'],
            $validated['actual_quantity'],
            $validated['reason'],
            $validated['notes'] ?? null
        );

        // Auto-post quick adjustments
        $this->adjustmentService->post($adjustment);

        return response()->json([
            'success' => true,
            'message' => 'Stock adjusted successfully.',
            'data' => new StockAdjustmentResource($adjustment->fresh()),
        ]);
    }
}
