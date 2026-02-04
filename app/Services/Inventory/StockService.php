<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    /**
     * Record a stock movement and update stock levels.
     */
    public function recordMovement(
        int $productId,
        int $warehouseId,
        string $movementType,
        string $direction,
        float $quantity,
        float $unitCost = 0,
        ?int $variantId = null,
        ?int $locationId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?int $fromWarehouseId = null,
        ?int $toWarehouseId = null,
        ?string $notes = null,
        ?int $createdBy = null
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $warehouseId, $movementType, $direction, $quantity,
            $unitCost, $variantId, $locationId, $referenceType, $referenceId,
            $referenceNumber, $fromWarehouseId, $toWarehouseId, $notes, $createdBy
        ) {
            // Get or create stock level
            $stockLevel = $this->getOrCreateStockLevel(
                $productId,
                $warehouseId,
                $variantId,
                $locationId
            );

            // Validate outgoing stock
            if ($direction === StockMovement::DIRECTION_OUT) {
                $warehouse = Warehouse::find($warehouseId);
                if (!$warehouse->allow_negative_stock && $stockLevel->quantity < $quantity) {
                    throw new InvalidArgumentException(
                        "Insufficient stock. Available: {$stockLevel->quantity}, Requested: {$quantity}"
                    );
                }
            }

            // Update stock quantity
            $newQuantity = $direction === StockMovement::DIRECTION_IN
                ? bcadd((string) $stockLevel->quantity, (string) $quantity, 4)
                : bcsub((string) $stockLevel->quantity, (string) $quantity, 4);

            // Update average cost for incoming movements
            if ($direction === StockMovement::DIRECTION_IN && $unitCost > 0) {
                $this->updateAverageCost($stockLevel, $quantity, $unitCost);
            }

            $stockLevel->quantity = $newQuantity;
            $stockLevel->last_purchase_price = $direction === StockMovement::DIRECTION_IN
                ? $unitCost
                : $stockLevel->last_purchase_price;
            $stockLevel->recalculateTotalValue();
            $stockLevel->save();

            // Record the movement
            return StockMovement::create([
                'organization_id' => $stockLevel->organization_id,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'movement_type' => $movementType,
                'direction' => $direction,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => bcmul((string) $quantity, (string) $unitCost, 4),
                'balance_after' => $newQuantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'notes' => $notes,
                'created_by' => $createdBy ?? auth()->id(),
            ]);
        });
    }

    /**
     * Record a purchase receipt (goods in).
     */
    public function recordPurchase(
        int $productId,
        int $warehouseId,
        float $quantity,
        float $unitCost,
        ?int $variantId = null,
        ?string $referenceNumber = null,
        ?int $referenceId = null
    ): StockMovement {
        return $this->recordMovement(
            productId: $productId,
            warehouseId: $warehouseId,
            movementType: StockMovement::TYPE_PURCHASE,
            direction: StockMovement::DIRECTION_IN,
            quantity: $quantity,
            unitCost: $unitCost,
            variantId: $variantId,
            referenceType: 'bill',
            referenceId: $referenceId,
            referenceNumber: $referenceNumber
        );
    }

    /**
     * Record a sale (goods out).
     */
    public function recordSale(
        int $productId,
        int $warehouseId,
        float $quantity,
        ?int $variantId = null,
        ?string $referenceNumber = null,
        ?int $referenceId = null
    ): StockMovement {
        $stockLevel = $this->getStockLevel($productId, $warehouseId, $variantId);
        $unitCost = $stockLevel?->average_cost ?? 0;

        return $this->recordMovement(
            productId: $productId,
            warehouseId: $warehouseId,
            movementType: StockMovement::TYPE_SALE,
            direction: StockMovement::DIRECTION_OUT,
            quantity: $quantity,
            unitCost: $unitCost,
            variantId: $variantId,
            referenceType: 'invoice',
            referenceId: $referenceId,
            referenceNumber: $referenceNumber
        );
    }

    /**
     * Transfer stock between warehouses.
     */
    public function transfer(
        int $productId,
        int $fromWarehouseId,
        int $toWarehouseId,
        float $quantity,
        ?int $variantId = null,
        ?string $referenceNumber = null,
        ?int $referenceId = null
    ): array {
        return DB::transaction(function () use (
            $productId, $fromWarehouseId, $toWarehouseId,
            $quantity, $variantId, $referenceNumber, $referenceId
        ) {
            $stockLevel = $this->getStockLevel($productId, $fromWarehouseId, $variantId);
            $unitCost = $stockLevel?->average_cost ?? 0;

            // Record transfer out
            $outMovement = $this->recordMovement(
                productId: $productId,
                warehouseId: $fromWarehouseId,
                movementType: StockMovement::TYPE_TRANSFER_OUT,
                direction: StockMovement::DIRECTION_OUT,
                quantity: $quantity,
                unitCost: $unitCost,
                variantId: $variantId,
                referenceType: 'stock_transfer',
                referenceId: $referenceId,
                referenceNumber: $referenceNumber,
                toWarehouseId: $toWarehouseId
            );

            // Record transfer in
            $inMovement = $this->recordMovement(
                productId: $productId,
                warehouseId: $toWarehouseId,
                movementType: StockMovement::TYPE_TRANSFER_IN,
                direction: StockMovement::DIRECTION_IN,
                quantity: $quantity,
                unitCost: $unitCost,
                variantId: $variantId,
                referenceType: 'stock_transfer',
                referenceId: $referenceId,
                referenceNumber: $referenceNumber,
                fromWarehouseId: $fromWarehouseId
            );

            return [
                'out' => $outMovement,
                'in' => $inMovement,
            ];
        });
    }

    /**
     * Adjust stock quantity (for corrections, damage, etc.).
     */
    public function adjust(
        int $productId,
        int $warehouseId,
        float $newQuantity,
        ?int $variantId = null,
        ?int $locationId = null,
        ?string $referenceNumber = null,
        ?int $referenceId = null,
        ?string $notes = null
    ): ?StockMovement {
        $stockLevel = $this->getStockLevel($productId, $warehouseId, $variantId, $locationId);
        $currentQuantity = $stockLevel?->quantity ?? 0;
        $difference = bcsub((string) $newQuantity, (string) $currentQuantity, 4);

        if (bccomp($difference, '0', 4) === 0) {
            return null; // No adjustment needed
        }

        $direction = bccomp($difference, '0', 4) > 0
            ? StockMovement::DIRECTION_IN
            : StockMovement::DIRECTION_OUT;

        return $this->recordMovement(
            productId: $productId,
            warehouseId: $warehouseId,
            movementType: StockMovement::TYPE_ADJUSTMENT,
            direction: $direction,
            quantity: abs((float) $difference),
            unitCost: $stockLevel?->average_cost ?? 0,
            variantId: $variantId,
            locationId: $locationId,
            referenceType: 'stock_adjustment',
            referenceId: $referenceId,
            referenceNumber: $referenceNumber,
            notes: $notes
        );
    }

    /**
     * Reserve stock for an order.
     */
    public function reserve(
        int $productId,
        int $warehouseId,
        float $quantity,
        ?int $variantId = null
    ): bool {
        $stockLevel = $this->getStockLevel($productId, $warehouseId, $variantId);

        if (!$stockLevel) {
            return false;
        }

        return $stockLevel->reserve($quantity);
    }

    /**
     * Release reserved stock.
     */
    public function release(
        int $productId,
        int $warehouseId,
        float $quantity,
        ?int $variantId = null
    ): void {
        $stockLevel = $this->getStockLevel($productId, $warehouseId, $variantId);

        if ($stockLevel) {
            $stockLevel->release($quantity);
        }
    }

    /**
     * Get stock level for a product/warehouse combination.
     */
    public function getStockLevel(
        int $productId,
        int $warehouseId,
        ?int $variantId = null,
        ?int $locationId = null
    ): ?StockLevel {
        return StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->first();
    }

    /**
     * Get or create a stock level record.
     */
    public function getOrCreateStockLevel(
        int $productId,
        int $warehouseId,
        ?int $variantId = null,
        ?int $locationId = null
    ): StockLevel {
        $product = Product::findOrFail($productId);

        return StockLevel::firstOrCreate(
            [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
            ],
            [
                'organization_id' => $product->organization_id,
                'quantity' => 0,
                'reserved_quantity' => 0,
                'average_cost' => 0,
                'total_value' => 0,
            ]
        );
    }

    /**
     * Get total stock for a product across all warehouses.
     */
    public function getTotalStock(int $productId, ?int $variantId = null): float
    {
        return StockLevel::where('product_id', $productId)
            ->when($variantId, fn($q) => $q->where('variant_id', $variantId))
            ->sum('quantity');
    }

    /**
     * Get available stock for a product across all warehouses.
     */
    public function getAvailableStock(int $productId, ?int $variantId = null): float
    {
        return StockLevel::where('product_id', $productId)
            ->when($variantId, fn($q) => $q->where('variant_id', $variantId))
            ->selectRaw('SUM(quantity - reserved_quantity) as available')
            ->value('available') ?? 0;
    }

    /**
     * Check if product has sufficient stock.
     */
    public function hasAvailableStock(
        int $productId,
        int $warehouseId,
        float $quantity,
        ?int $variantId = null
    ): bool {
        $stockLevel = $this->getStockLevel($productId, $warehouseId, $variantId);

        if (!$stockLevel) {
            return false;
        }

        return $stockLevel->hasAvailable($quantity);
    }

    /**
     * Get products that need reordering.
     */
    public function getLowStockProducts(?int $warehouseId = null): array
    {
        return StockLevel::with(['product', 'warehouse'])
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->lowStock()
            ->get()
            ->toArray();
    }

    /**
     * Update average cost using weighted average method.
     */
    protected function updateAverageCost(StockLevel $stockLevel, float $newQuantity, float $newUnitCost): void
    {
        $currentQuantity = (float) $stockLevel->quantity;
        $currentCost = (float) $stockLevel->average_cost;

        if ($currentQuantity <= 0) {
            $stockLevel->average_cost = $newUnitCost;
            return;
        }

        // Weighted average: ((current_qty * current_cost) + (new_qty * new_cost)) / (current_qty + new_qty)
        $totalValue = bcadd(
            bcmul((string) $currentQuantity, (string) $currentCost, 4),
            bcmul((string) $newQuantity, (string) $newUnitCost, 4),
            4
        );

        $totalQuantity = bcadd((string) $currentQuantity, (string) $newQuantity, 4);

        $stockLevel->average_cost = bcdiv($totalValue, $totalQuantity, 4);
    }

    /**
     * Get stock valuation report.
     */
    public function getStockValuation(?int $warehouseId = null): array
    {
        $query = StockLevel::with(['product', 'warehouse'])
            ->where('quantity', '>', 0)
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId));

        $items = $query->get();

        return [
            'items' => $items->map(fn($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'warehouse_id' => $item->warehouse_id,
                'warehouse_name' => $item->warehouse->name,
                'quantity' => $item->quantity,
                'average_cost' => $item->average_cost,
                'total_value' => $item->total_value,
            ])->toArray(),
            'totals' => [
                'total_items' => $items->count(),
                'total_quantity' => $items->sum('quantity'),
                'total_value' => $items->sum('total_value'),
            ],
        ];
    }
}
