<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\StockMovement;
use App\Services\Inventory\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * Get stock levels with filters.
     */
    public function levels(Request $request): JsonResponse
    {
        $query = StockLevel::with(['product', 'variant', 'warehouse', 'location']);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->has('warehouse_id')) {
            $query->inWarehouse($request->integer('warehouse_id'));
        }

        if ($request->boolean('low_stock_only', false)) {
            $query->lowStock();
        }

        if ($request->boolean('in_stock_only', false)) {
            $query->hasStock();
        }

        $levels = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $levels,
        ]);
    }

    /**
     * Get stock movements history.
     */
    public function movements(Request $request): JsonResponse
    {
        $query = StockMovement::with(['product', 'variant', 'warehouse', 'creator'])
            ->latest();

        if ($request->has('product_id')) {
            $query->forProduct($request->integer('product_id'));
        }

        if ($request->has('warehouse_id')) {
            $query->inWarehouse($request->integer('warehouse_id'));
        }

        if ($request->has('movement_type')) {
            $query->byType($request->input('movement_type'));
        }

        if ($request->has('direction')) {
            $request->input('direction') === 'in'
                ? $query->incoming()
                : $query->outgoing();
        }

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->input('to_date'));
        }

        $movements = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $movements,
        ]);
    }

    /**
     * Get stock valuation report.
     */
    public function valuation(Request $request): JsonResponse
    {
        $valuation = $this->stockService->getStockValuation(
            $request->input('warehouse_id')
        );

        return response()->json([
            'success' => true,
            'data' => $valuation,
        ]);
    }

    /**
     * Get low stock report.
     */
    public function lowStock(Request $request): JsonResponse
    {
        $items = $this->stockService->getLowStockProducts(
            $request->input('warehouse_id')
        );

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Check stock availability.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        $results = [];
        $allAvailable = true;

        foreach ($validated['items'] as $item) {
            $available = $this->stockService->hasAvailableStock(
                $item['product_id'],
                $item['warehouse_id'],
                $item['quantity'],
                $item['variant_id'] ?? null
            );

            $stockLevel = $this->stockService->getStockLevel(
                $item['product_id'],
                $item['warehouse_id'],
                $item['variant_id'] ?? null
            );

            $results[] = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'warehouse_id' => $item['warehouse_id'],
                'requested' => $item['quantity'],
                'available' => $stockLevel?->getAvailableQuantity() ?? 0,
                'is_available' => $available,
            ];

            if (!$available) {
                $allAvailable = false;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'all_available' => $allAvailable,
                'items' => $results,
            ],
        ]);
    }

    /**
     * Reserve stock.
     */
    public function reserve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required|numeric|gt:0',
        ]);

        $reserved = $this->stockService->reserve(
            $validated['product_id'],
            $validated['warehouse_id'],
            $validated['quantity'],
            $validated['variant_id'] ?? null
        );

        if (!$reserved) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_STOCK',
                    'message' => 'Insufficient stock available for reservation.',
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock reserved successfully.',
        ]);
    }

    /**
     * Release reserved stock.
     */
    public function release(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required|numeric|gt:0',
        ]);

        $this->stockService->release(
            $validated['product_id'],
            $validated['warehouse_id'],
            $validated['quantity'],
            $validated['variant_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Stock reservation released.',
        ]);
    }
}
