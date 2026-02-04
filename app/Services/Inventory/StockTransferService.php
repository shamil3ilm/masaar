<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferLine;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(
        private StockService $stockService,
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new stock transfer.
     */
    public function create(array $data, array $lines): StockTransfer
    {
        return DB::transaction(function () use ($data, $lines) {
            // Validate different warehouses
            if ($data['from_warehouse_id'] === $data['to_warehouse_id']) {
                throw new \InvalidArgumentException(
                    'Source and destination warehouses must be different.'
                );
            }

            // Generate transfer number if not provided
            if (empty($data['transfer_number'])) {
                $data['transfer_number'] = $this->numberGenerator->generate('TRF');
            }

            $transfer = StockTransfer::create($data);

            foreach ($lines as $lineData) {
                // Get unit cost from source warehouse
                $stockLevel = $this->stockService->getStockLevel(
                    $lineData['product_id'],
                    $transfer->from_warehouse_id,
                    $lineData['variant_id'] ?? null
                );

                $lineData['unit_cost'] = $stockLevel?->average_cost ?? 0;
                $transfer->lines()->create($lineData);
            }

            return $transfer->load('lines.product');
        });
    }

    /**
     * Update a draft transfer.
     */
    public function update(StockTransfer $transfer, array $data, ?array $lines = null): StockTransfer
    {
        if (!$transfer->isEditable()) {
            throw new \InvalidArgumentException('Only draft transfers can be updated.');
        }

        return DB::transaction(function () use ($transfer, $data, $lines) {
            // Validate different warehouses if changing
            if (isset($data['from_warehouse_id']) || isset($data['to_warehouse_id'])) {
                $fromId = $data['from_warehouse_id'] ?? $transfer->from_warehouse_id;
                $toId = $data['to_warehouse_id'] ?? $transfer->to_warehouse_id;

                if ($fromId === $toId) {
                    throw new \InvalidArgumentException(
                        'Source and destination warehouses must be different.'
                    );
                }
            }

            $transfer->update($data);

            if ($lines !== null) {
                $transfer->lines()->delete();

                foreach ($lines as $lineData) {
                    $stockLevel = $this->stockService->getStockLevel(
                        $lineData['product_id'],
                        $transfer->from_warehouse_id,
                        $lineData['variant_id'] ?? null
                    );

                    $lineData['unit_cost'] = $stockLevel?->average_cost ?? 0;
                    $transfer->lines()->create($lineData);
                }
            }

            return $transfer->fresh(['lines.product']);
        });
    }

    /**
     * Ship a transfer (move stock out of source warehouse).
     */
    public function ship(StockTransfer $transfer): StockTransfer
    {
        if (!$transfer->canShip()) {
            throw new \InvalidArgumentException('Transfer cannot be shipped.');
        }

        return DB::transaction(function () use ($transfer) {
            // Validate stock availability for all lines
            foreach ($transfer->lines as $line) {
                if (!$this->stockService->hasAvailableStock(
                    $line->product_id,
                    $transfer->from_warehouse_id,
                    $line->quantity_sent,
                    $line->variant_id
                )) {
                    throw new \InvalidArgumentException(
                        "Insufficient stock for product: {$line->product->name}"
                    );
                }
            }

            // Deduct stock from source warehouse
            foreach ($transfer->lines as $line) {
                $this->stockService->recordMovement(
                    productId: $line->product_id,
                    warehouseId: $transfer->from_warehouse_id,
                    movementType: 'transfer_out',
                    direction: 'out',
                    quantity: $line->quantity_sent,
                    unitCost: $line->unit_cost,
                    variantId: $line->variant_id,
                    referenceType: 'stock_transfer',
                    referenceId: $transfer->id,
                    referenceNumber: $transfer->transfer_number,
                    toWarehouseId: $transfer->to_warehouse_id
                );
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'shipped_at' => now(),
                'shipped_by' => auth()->id(),
            ]);

            return $transfer->fresh();
        });
    }

    /**
     * Receive a transfer (add stock to destination warehouse).
     */
    public function receive(StockTransfer $transfer, array $receivedQuantities = []): StockTransfer
    {
        if (!$transfer->canReceive()) {
            throw new \InvalidArgumentException('Transfer cannot be received.');
        }

        return DB::transaction(function () use ($transfer, $receivedQuantities) {
            foreach ($transfer->lines as $line) {
                // Use provided quantity or default to sent quantity
                $receivedQty = $receivedQuantities[$line->id] ?? $line->quantity_sent;

                // Update line with received quantity
                $line->update(['quantity_received' => $receivedQty]);

                // Add stock to destination warehouse
                if ($receivedQty > 0) {
                    $this->stockService->recordMovement(
                        productId: $line->product_id,
                        warehouseId: $transfer->to_warehouse_id,
                        movementType: 'transfer_in',
                        direction: 'in',
                        quantity: $receivedQty,
                        unitCost: $line->unit_cost,
                        variantId: $line->variant_id,
                        referenceType: 'stock_transfer',
                        referenceId: $transfer->id,
                        referenceNumber: $transfer->transfer_number,
                        fromWarehouseId: $transfer->from_warehouse_id
                    );
                }
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => auth()->id(),
            ]);

            return $transfer->fresh(['lines.product']);
        });
    }

    /**
     * Cancel a transfer.
     */
    public function cancel(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status === StockTransfer::STATUS_RECEIVED) {
            throw new \InvalidArgumentException('Received transfers cannot be cancelled.');
        }

        return DB::transaction(function () use ($transfer) {
            // If in transit, return stock to source warehouse
            if ($transfer->status === StockTransfer::STATUS_IN_TRANSIT) {
                foreach ($transfer->lines as $line) {
                    $this->stockService->recordMovement(
                        productId: $line->product_id,
                        warehouseId: $transfer->from_warehouse_id,
                        movementType: 'transfer_in',
                        direction: 'in',
                        quantity: $line->quantity_sent,
                        unitCost: $line->unit_cost,
                        variantId: $line->variant_id,
                        referenceType: 'stock_transfer',
                        referenceId: $transfer->id,
                        referenceNumber: $transfer->transfer_number . '-CANCEL',
                        notes: 'Transfer cancelled - stock returned'
                    );
                }
            }

            $transfer->update(['status' => StockTransfer::STATUS_CANCELLED]);

            return $transfer->fresh();
        });
    }

    /**
     * Get transfer summary.
     */
    public function getSummary(StockTransfer $transfer): array
    {
        $lines = $transfer->lines;

        $summary = [
            'total_lines' => $lines->count(),
            'total_quantity_sent' => $lines->sum('quantity_sent'),
            'total_value' => $lines->sum(fn($l) => $l->getTotalValue()),
        ];

        if ($transfer->status === StockTransfer::STATUS_RECEIVED) {
            $summary['total_quantity_received'] = $lines->sum('quantity_received');
            $summary['fully_received'] = $lines->filter(fn($l) => $l->isFullyReceived())->count();
            $summary['with_shortage'] = $lines->filter(fn($l) => $l->hasShortage())->count();
            $summary['with_excess'] = $lines->filter(fn($l) => $l->hasExcess())->count();
        }

        return $summary;
    }

    /**
     * Get pending transfers for a warehouse.
     */
    public function getPendingForWarehouse(int $warehouseId): array
    {
        $incoming = StockTransfer::with(['fromWarehouse', 'lines.product'])
            ->where('to_warehouse_id', $warehouseId)
            ->where('status', StockTransfer::STATUS_IN_TRANSIT)
            ->get();

        $outgoing = StockTransfer::with(['toWarehouse', 'lines.product'])
            ->where('from_warehouse_id', $warehouseId)
            ->where('status', StockTransfer::STATUS_IN_TRANSIT)
            ->get();

        return [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
        ];
    }

    /**
     * Get overdue transfers.
     */
    public function getOverdue(): array
    {
        return StockTransfer::with(['fromWarehouse', 'toWarehouse', 'lines.product'])
            ->overdue()
            ->get()
            ->toArray();
    }
}
