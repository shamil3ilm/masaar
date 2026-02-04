<?php

declare(strict_types=1);

namespace App\Events\HR;

use App\Models\HR\Payslip;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PayslipGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Payslip $payslip
    ) {}
}
