<?php

declare(strict_types=1);

namespace App\Events\CRM;

use App\Models\CRM\Opportunity;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OpportunityWon
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Opportunity $opportunity,
        public ?string $wonReason = null
    ) {}
}
