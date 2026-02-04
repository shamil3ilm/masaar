<?php

declare(strict_types=1);

namespace App\Listeners\Inventory;

use App\Events\Inventory\LowStockAlert;
use App\Events\Inventory\StockLevelChanged;
use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use Illuminate\Contracts\Queue\ShouldQueue;

class CheckLowStockListener implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(StockLevelChanged $event): void
    {
        // Only trigger alert if we just crossed below the reorder level
        if (!$event->crossedReorderLevel()) {
            return;
        }

        $stockLevel = $event->stockLevel;
        $product = Product::find($stockLevel->product_id);
        $warehouse = Warehouse::find($stockLevel->warehouse_id);

        if (!$product || !$warehouse) {
            return;
        }

        // Dispatch low stock alert event
        LowStockAlert::dispatch(
            $product,
            $warehouse,
            (float) $stockLevel->quantity,
            (float) $stockLevel->reorder_level,
            (float) $stockLevel->reorder_quantity
        );
    }
}
