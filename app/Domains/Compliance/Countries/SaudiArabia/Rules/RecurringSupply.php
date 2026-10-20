<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\RecurringSupply as RecurringSupplyPort;
use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Models\Invoice;

/**
 * A Saudi invoice states a periodic supply by its period alone: the supply
 * date (KSA-5) and the supply end date (KSA-24), which the end date needs
 * beside it and may not precede (BR-KSA-35, BR-KSA-36; XML Implementation
 * Standard v1.2). Every run already carries both, so nothing is added.
 */
class RecurringSupply implements RecurringSupplyPort
{
    public function periodic(array $payload, ScheduleFrequency $frequency): array
    {
        return $payload;
    }

    /** A Saudi invoice keeps everything it says in the shared columns. */
    public function fromInvoice(Invoice $invoice): array
    {
        return [];
    }
}
