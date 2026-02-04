<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\StockTransferResource;
use App\Models\Inventory\StockTransfer;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockTransferController extends Controller
{
    public function __construct(
        private StockTransferService $transferService
    ) {}

    /**
     * List stock transfers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockTransfer::with(['fromWarehouse', 'toWarehouse', 'creator'])
            ->latest();

        if ($request->has('from_warehouse_id')) {
            $query->fromWarehouse($request->integer('from_warehouse_id'));
        }

        if ($request->has('to_warehouse_id')) {
            $query->toWarehouse($request->integer('to_warehouse_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('from_date')) {
            $query->where('transfer_date', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('transfer_date', '<=', $request->input('to_date'));
        }

        $transfers = $query->paginate($request->integer('per_page', 15));

        return StockTransferResource::collection($transfers);
    }

    /**
     * Create a new stock transfer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|integer|exists:warehouses,id',
            'to_warehouse_id' => 'required|integer|exists:warehouses,id|different:from_warehouse_id',
            'transfer_date' => 'required|date',
            'expected_arrival_date' => 'nullable|date|after_or_equal:transfer_date',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.quantity_sent' => 'required|numeric|gt:0',
            'lines.*.notes' => 'nullable|string|max:255',
        ]);

        $transfer = $this->transferService->create(
            collect($validated)->except('lines')->toArray(),
            $validated['lines']
        );

        return response()->json([
            'success' => true,
            'message' => 'Stock transfer created successfully.',
            'data' => new StockTransferResource($transfer),
        ], 201);
    }

    /**
     * Show a stock transfer.
     */
    public function show(StockTransfer $stockTransfer): JsonResponse
    {
        $stockTransfer->load([
            'fromWarehouse', 'toWarehouse', 'lines.product', 'lines.variant',
            'creator', 'shipper', 'receiver',
        ]);

        return response()->json([
            'success' => true,
            'data' => new StockTransferResource($stockTransfer),
        ]);
    }

    /**
     * Update a draft stock transfer.
     */
    public function update(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'sometimes|integer|exists:warehouses,id',
            'to_warehouse_id' => 'sometimes|integer|exists:warehouses,id',
            'transfer_date' => 'sometimes|date',
            'expected_arrival_date' => 'nullable|date|after_or_equal:transfer_date',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'nullable|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.quantity_sent' => 'required|numeric|gt:0',
            'lines.*.notes' => 'nullable|string|max:255',
        ]);

        $transfer = $this->transferService->update(
            $stockTransfer,
            collect($validated)->except('lines')->toArray(),
            $validated['lines'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Stock transfer updated successfully.',
            'data' => new StockTransferResource($transfer),
        ]);
    }

    /**
     * Ship a stock transfer.
     */
    public function ship(StockTransfer $stockTransfer): JsonResponse
    {
        $transfer = $this->transferService->ship($stockTransfer);

        return response()->json([
            'success' => true,
            'message' => 'Stock transfer shipped successfully.',
            'data' => new StockTransferResource($transfer),
        ]);
    }

    /**
     * Receive a stock transfer.
     */
    public function receive(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $validated = $request->validate([
            'received_quantities' => 'nullable|array',
            'received_quantities.*' => 'numeric|min:0',
        ]);

        $transfer = $this->transferService->receive(
            $stockTransfer,
            $validated['received_quantities'] ?? []
        );

        return response()->json([
            'success' => true,
            'message' => 'Stock transfer received successfully.',
            'data' => new StockTransferResource($transfer),
        ]);
    }

    /**
     * Cancel a stock transfer.
     */
    public function cancel(StockTransfer $stockTransfer): JsonResponse
    {
        $transfer = $this->transferService->cancel($stockTransfer);

        return response()->json([
            'success' => true,
            'message' => 'Stock transfer cancelled.',
            'data' => new StockTransferResource($transfer),
        ]);
    }

    /**
     * Get transfer summary.
     */
    public function summary(StockTransfer $stockTransfer): JsonResponse
    {
        $summary = $this->transferService->getSummary($stockTransfer);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Get pending transfers for a warehouse.
     */
    public function pending(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $pending = $this->transferService->getPendingForWarehouse(
            $request->integer('warehouse_id')
        );

        return response()->json([
            'success' => true,
            'data' => $pending,
        ]);
    }

    /**
     * Get overdue transfers.
     */
    public function overdue(): JsonResponse
    {
        $overdue = $this->transferService->getOverdue();

        return response()->json([
            'success' => true,
            'data' => $overdue,
        ]);
    }
}
