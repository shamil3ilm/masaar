<?php

declare(strict_types=1);

namespace App\Events\Purchase;

use App\Models\Purchase\PurchaseOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public array $receivedQuantities,
        public bool $isFullyReceived
    ) {}
}
