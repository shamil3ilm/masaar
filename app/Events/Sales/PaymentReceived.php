<?php

declare(strict_types=1);

namespace App\Events\Sales;

use App\Models\Sales\PaymentReceived as PaymentReceivedModel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PaymentReceivedModel $payment
    ) {}
}
