<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\ERP\InsufficientStockException;
use App\Models\Inventory\InventoryBatch;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryAllocationService
{
    /**
     * Allocation methods.
     */
    public const METHOD_FIFO = 'fifo';    // First In, First Out
    public const METHOD_LIFO = 'lifo';    // Last In, First Out
    public const METHOD_FEFO = 'fefo';    // First Expired, First Out
    public const METHOD_MANUAL = 'manual'; // Manual batch selection

    /**
     * Check if sufficient stock is available.
     */
    public function checkAvailability(
        int $productId,
        string $quantity,
        ?int $warehouseId = null,
        bool $checkBatches = false
    ): AvailabilityResult {
        $product = Product::find($productId);

        if (!$product) {
            return new AvailabilityResult(false, '0', '0', 'Product not found');
        }

        // Get stock levels
        $query = StockLevel::where('product_id', $productId);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $stockLevels = $query->get();
        $totalAvailable = '0';
        $totalReserved = '0';

        foreach ($stockLevels as $level) {
            $available = bcsub($level->quantity, $level->reserved_quantity, 4);
            $totalAvailable = bcadd($totalAvailable, $available, 4);
            $totalReserved = bcadd($totalReserved, $level->reserved_quantity, 4);
        }

        // If batch tracking, verify batch availability
        if ($checkBatches && $product->track_batches) {
            $batchAvailable = $this->getBatchAvailability($productId, $warehouseId);
            if (bccomp($batchAvailable, $totalAvailable, 4) < 0) {
                $totalAvailable = $batchAvailable;
            }
        }

        $isAvailable = bccomp($totalAvailable, $quantity, 4) >= 0;

        return new AvailabilityResult(
            isAvailable: $isAvailable,
            availableQuantity: $totalAvailable,
            reservedQuantity: $totalReserved,
            message: $isAvailable ? null : 'Insufficient stock',
            allowNegative: $product->allow_negative_stock
        );
    }

    /**
     * Get available quantity from batches.
     */
    protected function getBatchAvailability(int $productId, ?int $warehouseId): string
    {
        $query = InventoryBatch::where('product_id', $productId)
            ->available()
            ->notExpired();

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $batches = $query->get();
        $total = '0';

        foreach ($batches as $batch) {
            $total = bcadd($total, $batch->getAvailableQuantity(), 4);
        }

        return $total;
    }

    /**
     * Allocate stock for a sale/transfer.
     */
    public function allocate(
        int $productId,
        string $quantity,
        ?int $warehouseId = null,
        string $method = self::METHOD_FIFO,
        ?array $batchIds = null
    ): AllocationResult {
        $product = Product::find($productId);

        if (!$product) {
            throw new \RuntimeException("Product not found: {$productId}");
        }

        // Check availability first
        $availability = $this->checkAvailability($productId, $quantity, $warehouseId, $product->track_batches);

        if (!$availability->isAvailable && !$product->allow_negative_stock) {
            throw new InsufficientStockException(
                "Insufficient stock for product {$product->name}",
                $productId,
                $quantity,
                $availability->availableQuantity
            );
        }

        return DB::transaction(function () use ($product, $productId, $quantity, $warehouseId, $method, $batchIds) {
            $allocations = [];
            $remainingQty = $quantity;
            $totalCost = '0';

            if ($product->track_batches) {
                // Allocate from batches
                $allocations = $this->allocateFromBatches(
                    $productId,
                    $remainingQty,
                    $warehouseId,
                    $method,
                    $batchIds
                );

                foreach ($allocations as $allocation) {
                    $remainingQty = bcsub($remainingQty, $allocation['quantity'], 4);
                    $totalCost = bcadd($totalCost, $allocation['total_cost'], 4);
                }
            }

            // Update stock levels
            $this->updateStockLevels($productId, $quantity, $warehouseId, 'reserve');

            // Calculate average cost if no batches
            if (empty($allocations)) {
                $stockLevel = StockLevel::where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->first();

                $unitCost = $stockLevel?->average_cost ?? $product->purchase_price ?? '0';
                $totalCost = bcmul($quantity, $unitCost, 4);

                $allocations[] = [
                    'batch_id' => null,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ];
            }

            return new AllocationResult(
                allocations: $allocations,
                totalQuantity: $quantity,
                totalCost: $totalCost,
                averageCost: bcdiv($totalCost, $quantity, 4),
                method: $method
            );
        });
    }

    /**
     * Allocate from specific batches.
     */
    protected function allocateFromBatches(
        int $productId,
        string $quantity,
        ?int $warehouseId,
        string $method,
        ?array $batchIds
    ): array {
        if ($method === self::METHOD_MANUAL && !empty($batchIds)) {
            return $this->allocateManual($productId, $quantity, $batchIds);
        }

        $query = InventoryBatch::where('product_id', $productId)
            ->available()
            ->notExpired();

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        // Apply ordering based on method
        match ($method) {
            self::METHOD_FEFO => $query->fefo(),
            self::METHOD_LIFO => $query->lifo(),
            default => $query->fifo(),
        };

        $batches = $query->get();
        $allocations = [];
        $remaining = $quantity;

        foreach ($batches as $batch) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            $available = $batch->getAvailableQuantity();
            $toAllocate = bccomp($available, $remaining, 4) >= 0 ? $remaining : $available;

            if (bccomp($toAllocate, '0', 4) > 0) {
                $batch->reserve($toAllocate);

                $allocations[] = [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'quantity' => $toAllocate,
                    'unit_cost' => $batch->unit_cost,
                    'total_cost' => bcmul($toAllocate, $batch->unit_cost, 4),
                ];

                $remaining = bcsub($remaining, $toAllocate, 4);
            }
        }

        return $allocations;
    }

    /**
     * Manually allocate from specific batches.
     */
    protected function allocateManual(int $productId, string $quantity, array $batchIds): array
    {
        $allocations = [];
        $remaining = $quantity;

        foreach ($batchIds as $batchAllocation) {
            $batchId = $batchAllocation['batch_id'];
            $allocateQty = $batchAllocation['quantity'] ?? null;

            $batch = InventoryBatch::find($batchId);

            if (!$batch || $batch->product_id !== $productId) {
                continue;
            }

            $available = $batch->getAvailableQuantity();
            $toAllocate = $allocateQty
                ? min($allocateQty, $available, $remaining)
                : min($available, $remaining);

            if (bccomp($toAllocate, '0', 4) > 0) {
                $batch->reserve($toAllocate);

                $allocations[] = [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'quantity' => $toAllocate,
                    'unit_cost' => $batch->unit_cost,
                    'total_cost' => bcmul($toAllocate, $batch->unit_cost, 4),
                ];

                $remaining = bcsub($remaining, $toAllocate, 4);
            }

            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }
        }

        return $allocations;
    }

    /**
     * Release allocation (cancel reservation).
     */
    public function release(int $productId, string $quantity, ?int $warehouseId = null, ?array $allocations = null): void
    {
        DB::transaction(function () use ($productId, $quantity, $warehouseId, $allocations) {
            // Release batch reservations
            if ($allocations) {
                foreach ($allocations as $allocation) {
                    if ($allocation['batch_id']) {
                        $batch = InventoryBatch::find($allocation['batch_id']);
                        $batch?->release($allocation['quantity']);
                    }
                }
            }

            // Update stock levels
            $this->updateStockLevels($productId, $quantity, $warehouseId, 'release');
        });
    }

    /**
     * Confirm allocation (actual stock deduction).
     */
    public function confirm(int $productId, string $quantity, ?int $warehouseId = null, ?array $allocations = null): void
    {
        DB::transaction(function () use ($productId, $quantity, $warehouseId, $allocations) {
            // Deduct from batches
            if ($allocations) {
                foreach ($allocations as $allocation) {
                    if ($allocation['batch_id']) {
                        $batch = InventoryBatch::find($allocation['batch_id']);
                        if ($batch) {
                            $batch->release($allocation['quantity']);
                            $batch->deduct($allocation['quantity']);
                        }
                    }
                }
            }

            // Update stock levels
            $this->updateStockLevels($productId, $quantity, $warehouseId, 'deduct');
        });
    }

    /**
     * Update stock levels.
     */
    protected function updateStockLevels(int $productId, string $quantity, ?int $warehouseId, string $operation): void
    {
        $query = StockLevel::where('product_id', $productId);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $stockLevels = $query->lockForUpdate()->get();

        $remaining = $quantity;

        foreach ($stockLevels as $level) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            $toUpdate = $remaining;

            switch ($operation) {
                case 'reserve':
                    $available = bcsub($level->quantity, $level->reserved_quantity, 4);
                    $toUpdate = min($toUpdate, $available);
                    $level->reserved_quantity = bcadd($level->reserved_quantity, $toUpdate, 4);
                    break;

                case 'release':
                    $toUpdate = min($toUpdate, $level->reserved_quantity);
                    $level->reserved_quantity = bcsub($level->reserved_quantity, $toUpdate, 4);
                    break;

                case 'deduct':
                    $level->quantity = bcsub($level->quantity, $toUpdate, 4);
                    if (bccomp($level->reserved_quantity, $toUpdate, 4) >= 0) {
                        $level->reserved_quantity = bcsub($level->reserved_quantity, $toUpdate, 4);
                    }
                    break;
            }

            $level->save();
            $remaining = bcsub($remaining, $toUpdate, 4);
        }
    }

    /**
     * Get expiring batches.
     */
    public function getExpiringBatches(int $organizationId, int $days = 30): Collection
    {
        return InventoryBatch::where('organization_id', $organizationId)
            ->available()
            ->expiringSoon($days)
            ->with(['product', 'warehouse'])
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Get expired batches.
     */
    public function getExpiredBatches(int $organizationId): Collection
    {
        return InventoryBatch::where('organization_id', $organizationId)
            ->expired()
            ->where('status', '!=', InventoryBatch::STATUS_EXPIRED)
            ->with(['product', 'warehouse'])
            ->get();
    }

    /**
     * Mark expired batches as expired.
     */
    public function processExpiredBatches(int $organizationId): int
    {
        $count = 0;

        InventoryBatch::where('organization_id', $organizationId)
            ->expired()
            ->where('status', InventoryBatch::STATUS_AVAILABLE)
            ->chunk(100, function ($batches) use (&$count) {
                foreach ($batches as $batch) {
                    $batch->markAsExpired();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Convert quantity between units.
     */
    public function convertQuantity(string $quantity, int $fromUnitId, int $toUnitId): string
    {
        if ($fromUnitId === $toUnitId) {
            return $quantity;
        }

        $fromUnit = \App\Models\Inventory\UnitOfMeasure::find($fromUnitId);
        $toUnit = \App\Models\Inventory\UnitOfMeasure::find($toUnitId);

        if (!$fromUnit || !$toUnit) {
            throw new \RuntimeException('Invalid unit conversion');
        }

        // Convert to base unit first, then to target unit
        $baseQuantity = bcmul($quantity, (string) $fromUnit->conversion_factor, 6);
        return bcdiv($baseQuantity, (string) $toUnit->conversion_factor, 6);
    }

    /**
     * Calculate inventory valuation.
     */
    public function calculateValuation(
        int $productId,
        ?int $warehouseId = null,
        string $method = 'weighted_average'
    ): ValuationResult {
        $product = Product::find($productId);

        if ($product->track_batches) {
            return $this->calculateBatchValuation($productId, $warehouseId);
        }

        $query = StockLevel::where('product_id', $productId);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $stockLevels = $query->get();
        $totalQuantity = '0';
        $totalValue = '0';

        foreach ($stockLevels as $level) {
            $totalQuantity = bcadd($totalQuantity, $level->quantity, 4);
            $value = bcmul($level->quantity, $level->average_cost, 4);
            $totalValue = bcadd($totalValue, $value, 4);
        }

        $averageCost = bccomp($totalQuantity, '0', 4) > 0
            ? bcdiv($totalValue, $totalQuantity, 4)
            : '0';

        return new ValuationResult(
            quantity: $totalQuantity,
            totalValue: $totalValue,
            averageCost: $averageCost,
            method: $method
        );
    }

    /**
     * Calculate valuation from batches.
     */
    protected function calculateBatchValuation(int $productId, ?int $warehouseId): ValuationResult
    {
        $query = InventoryBatch::where('product_id', $productId)
            ->where('status', InventoryBatch::STATUS_AVAILABLE);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $batches = $query->get();
        $totalQuantity = '0';
        $totalValue = '0';

        foreach ($batches as $batch) {
            $available = $batch->getAvailableQuantity();
            $totalQuantity = bcadd($totalQuantity, $available, 4);
            $value = bcmul($available, $batch->unit_cost, 4);
            $totalValue = bcadd($totalValue, $value, 4);
        }

        $averageCost = bccomp($totalQuantity, '0', 4) > 0
            ? bcdiv($totalValue, $totalQuantity, 4)
            : '0';

        return new ValuationResult(
            quantity: $totalQuantity,
            totalValue: $totalValue,
            averageCost: $averageCost,
            method: 'batch_actual'
        );
    }
}

// Result classes

class AvailabilityResult
{
    public function __construct(
        public readonly bool $isAvailable,
        public readonly string $availableQuantity,
        public readonly string $reservedQuantity,
        public readonly ?string $message = null,
        public readonly bool $allowNegative = false
    ) {}
}

class AllocationResult
{
    public function __construct(
        public readonly array $allocations,
        public readonly string $totalQuantity,
        public readonly string $totalCost,
        public readonly string $averageCost,
        public readonly string $method
    ) {}
}

class ValuationResult
{
    public function __construct(
        public readonly string $quantity,
        public readonly string $totalValue,
        public readonly string $averageCost,
        public readonly string $method
    ) {}
}
