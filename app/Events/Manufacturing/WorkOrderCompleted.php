<?php

declare(strict_types=1);

namespace App\Events\Manufacturing;

use App\Models\Manufacturing\WorkOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOrderCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WorkOrder $workOrder,
        public float $producedQuantity,
        public float $rejectedQuantity
    ) {}

    public function getGoodQuantity(): float
    {
        return $this->producedQuantity - $this->rejectedQuantity;
    }

    public function getYieldPercentage(): float
    {
        if ($this->producedQuantity === 0.0) {
            return 0;
        }

        return round(($this->getGoodQuantity() / $this->producedQuantity) * 100, 2);
    }
}
