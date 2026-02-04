<?php

declare(strict_types=1);

namespace App\Listeners\Manufacturing;

use App\Events\Manufacturing\WorkOrderCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateProductCostListener implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(WorkOrderCompleted $event): void
    {
        $workOrder = $event->workOrder;
        $product = $workOrder->product;

        if (!$product) {
            return;
        }

        // Calculate the manufacturing cost per unit
        $goodQuantity = $event->getGoodQuantity();

        if ($goodQuantity <= 0) {
            return;
        }

        $totalCost = $workOrder->getTotalActualCost();
        $unitCost = bcdiv((string) $totalCost, (string) $goodQuantity, 4);

        // Update product's manufacturing cost (for reference)
        // This could be used for standard costing or to update weighted average
        $product->update([
            'last_manufacturing_cost' => $unitCost,
            'last_manufactured_at' => now(),
        ]);

        // Log for costing analysis
        \Log::info('Manufacturing cost updated', [
            'work_order_id' => $workOrder->id,
            'work_order_number' => $workOrder->work_order_number,
            'product_id' => $product->id,
            'good_quantity' => $goodQuantity,
            'total_cost' => $totalCost,
            'unit_cost' => $unitCost,
        ]);
    }
}
