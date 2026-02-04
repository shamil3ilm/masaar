<?php

declare(strict_types=1);

namespace App\Events\Inventory;

use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockAlert
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Product $product,
        public Warehouse $warehouse,
        public float $currentQuantity,
        public float $reorderLevel,
        public float $reorderQuantity
    ) {}

    public function getShortage(): float
    {
        return max(0, $this->reorderLevel - $this->currentQuantity);
    }

    public function getSuggestedOrderQuantity(): float
    {
        return $this->reorderQuantity + $this->getShortage();
    }
}
