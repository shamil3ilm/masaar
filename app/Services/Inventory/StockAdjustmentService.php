<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentLine;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\StockMovement;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    public function __construct(
        private StockService $stockService,
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new stock adjustment.
     */
    public function create(array $data, array $lines): StockAdjustment
    {
        return DB::transaction(function () use ($data, $lines) {
            // Generate adjustment number if not provided
            if (empty($data['adjustment_number'])) {
                $data['adjustment_number'] = $this->numberGenerator->generate('ADJ');
            }

            $adjustment = StockAdjustment::create($data);

            foreach ($lines as $lineData) {
                // Get current system quantity
                $stockLevel = $this->stockService->getStockLevel(
                    $lineData['product_id'],
                    $adjustment->warehouse_id,
                    $lineData['variant_id'] ?? null,
                    $lineData['location_id'] ?? null
                );

                $lineData['system_quantity'] = $stockLevel?->quantity ?? 0;
                $lineData['unit_cost'] = $stockLevel?->average_cost ?? 0;

                $adjustment->lines()->create($lineData);
            }

            return $adjustment->load('lines.product');
        });
    }

    /**
     * Update a draft adjustment.
     */
    public function update(StockAdjustment $adjustment, array $data, ?array $lines = null): StockAdjustment
    {
        if (!$adjustment->isEditable()) {
            throw new \InvalidArgumentException('Only draft adjustments can be updated.');
        }

        return DB::transaction(function () use ($adjustment, $data, $lines) {
            $adjustment->update($data);

            if ($lines !== null) {
                // Remove existing lines and recreate
                $adjustment->lines()->delete();

                foreach ($lines as $lineData) {
                    $stockLevel = $this->stockService->getStockLevel(
                        $lineData['product_id'],
                        $adjustment->warehouse_id,
                        $lineData['variant_id'] ?? null,
                        $lineData['location_id'] ?? null
                    );

                    $lineData['system_quantity'] = $stockLevel?->quantity ?? 0;
                    $lineData['unit_cost'] = $stockLevel?->average_cost ?? 0;

                    $adjustment->lines()->create($lineData);
                }
            }

            return $adjustment->fresh(['lines.product']);
        });
    }

    /**
     * Post (approve) a stock adjustment.
     */
    public function post(StockAdjustment $adjustment): StockAdjustment
    {
        if (!$adjustment->canPost()) {
            throw new \InvalidArgumentException('Adjustment cannot be posted.');
        }

        return DB::transaction(function () use ($adjustment) {
            foreach ($adjustment->lines as $line) {
                if ($line->hasNoChange()) {
                    continue;
                }

                // Record stock movement
                $this->stockService->adjust(
                    productId: $line->product_id,
                    warehouseId: $adjustment->warehouse_id,
                    newQuantity: $line->actual_quantity,
                    variantId: $line->variant_id,
                    locationId: $line->location_id,
                    referenceNumber: $adjustment->adjustment_number,
                    referenceId: $adjustment->id,
                    notes: $line->notes ?? $adjustment->notes
                );
            }

            $adjustment->update([
                'status' => StockAdjustment::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            return $adjustment->fresh();
        });
    }

    /**
     * Cancel a draft adjustment.
     */
    public function cancel(StockAdjustment $adjustment): StockAdjustment
    {
        if ($adjustment->status !== StockAdjustment::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only draft adjustments can be cancelled.');
        }

        $adjustment->update(['status' => StockAdjustment::STATUS_CANCELLED]);

        return $adjustment->fresh();
    }

    /**
     * Create a quick adjustment for a single product.
     */
    public function quickAdjust(
        int $productId,
        int $warehouseId,
        float $actualQuantity,
        string $reason,
        ?string $notes = null
    ): StockAdjustment {
        return $this->create(
            [
                'warehouse_id' => $warehouseId,
                'adjustment_date' => now()->toDateString(),
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ],
            [
                [
                    'product_id' => $productId,
                    'actual_quantity' => $actualQuantity,
                ],
            ]
        );
    }

    /**
     * Create adjustment from stock count.
     */
    public function createFromStockCount(
        int $warehouseId,
        array $countedItems,
        ?string $notes = null
    ): StockAdjustment {
        $lines = [];

        foreach ($countedItems as $item) {
            $lines[] = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'location_id' => $item['location_id'] ?? null,
                'actual_quantity' => $item['counted_quantity'],
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $this->create(
            [
                'warehouse_id' => $warehouseId,
                'adjustment_date' => now()->toDateString(),
                'reason' => StockAdjustment::REASON_COUNT_CORRECTION,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ],
            $lines
        );
    }

    /**
     * Get adjustment summary.
     */
    public function getSummary(StockAdjustment $adjustment): array
    {
        $lines = $adjustment->lines;

        return [
            'total_lines' => $lines->count(),
            'increases' => $lines->filter(fn($l) => $l->isIncrease())->count(),
            'decreases' => $lines->filter(fn($l) => $l->isDecrease())->count(),
            'no_change' => $lines->filter(fn($l) => $l->hasNoChange())->count(),
            'net_quantity_change' => $lines->sum('difference'),
            'total_value_impact' => $lines->sum('total_cost'),
        ];
    }
}
