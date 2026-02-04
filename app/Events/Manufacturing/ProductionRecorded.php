<?php

declare(strict_types=1);

namespace App\Events\Manufacturing;

use App\Models\Manufacturing\ProductionLog;
use App\Models\Manufacturing\WorkOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductionRecorded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WorkOrder $workOrder,
        public ProductionLog $productionLog
    ) {}
}
