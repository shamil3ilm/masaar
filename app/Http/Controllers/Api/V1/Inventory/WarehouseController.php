<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\WarehouseResource;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WarehouseController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * List warehouses.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Warehouse::with(['branch', 'manager']);

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        $warehouses = $request->boolean('paginate', true)
            ? $query->paginate($request->integer('per_page', 15))
            : $query->get();

        return WarehouseResource::collection($warehouses);
    }

    /**
     * Create a new warehouse.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:warehouses,code',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|size:2',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
            'manager_id' => 'nullable|integer|exists:users,id',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'allow_negative_stock' => 'boolean',
        ]);

        // If setting as default, unset other defaults
        if ($validated['is_default'] ?? false) {
            Warehouse::where('is_default', true)->update(['is_default' => false]);
        }

        $warehouse = Warehouse::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Warehouse created successfully.',
            'data' => new WarehouseResource($warehouse),
        ], 201);
    }

    /**
     * Show a warehouse.
     */
    public function show(Warehouse $warehouse): JsonResponse
    {
        $warehouse->load(['branch', 'manager', 'locations']);

        return response()->json([
            'success' => true,
            'data' => new WarehouseResource($warehouse),
        ]);
    }

    /**
     * Update a warehouse.
     */
    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'name' => 'sometimes|required|string|max:100',
            'code' => 'sometimes|required|string|max:20|unique:warehouses,code,' . $warehouse->id,
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|size:2',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
            'manager_id' => 'nullable|integer|exists:users,id',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'allow_negative_stock' => 'boolean',
        ]);

        // If setting as default, unset other defaults
        if (($validated['is_default'] ?? false) && !$warehouse->is_default) {
            Warehouse::where('is_default', true)->update(['is_default' => false]);
        }

        $warehouse->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Warehouse updated successfully.',
            'data' => new WarehouseResource($warehouse->fresh()),
        ]);
    }

    /**
     * Delete a warehouse.
     */
    public function destroy(Warehouse $warehouse): JsonResponse
    {
        // Check for stock
        if ($warehouse->stockLevels()->where('quantity', '>', 0)->exists()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Cannot delete warehouse with existing stock.',
                ],
            ], 422);
        }

        // Check if it's the only warehouse
        if (Warehouse::count() === 1) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Cannot delete the only warehouse.',
                ],
            ], 422);
        }

        $warehouse->delete();

        return response()->json([
            'success' => true,
            'message' => 'Warehouse deleted successfully.',
        ]);
    }

    /**
     * Get stock valuation for a warehouse.
     */
    public function stockValuation(Warehouse $warehouse): JsonResponse
    {
        $valuation = $this->stockService->getStockValuation($warehouse->id);

        return response()->json([
            'success' => true,
            'data' => $valuation,
        ]);
    }

    /**
     * Get low stock items in a warehouse.
     */
    public function lowStock(Warehouse $warehouse): JsonResponse
    {
        $items = $this->stockService->getLowStockProducts($warehouse->id);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Set warehouse as default.
     */
    public function setDefault(Warehouse $warehouse): JsonResponse
    {
        Warehouse::where('is_default', true)->update(['is_default' => false]);
        $warehouse->update(['is_default' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Default warehouse updated.',
            'data' => new WarehouseResource($warehouse->fresh()),
        ]);
    }
}
