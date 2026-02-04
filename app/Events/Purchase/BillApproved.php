<?php

declare(strict_types=1);

namespace App\Events\Purchase;

use App\Models\Purchase\Bill;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Bill $bill
    ) {}
}
