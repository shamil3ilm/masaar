<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Invoice\Enums\ScheduleFrequency;

/**
 * What a country's invoice says about a supply that recurs, beyond the
 * period it bills.
 *
 * Every schedule's run states its period as the invoice's supply date and
 * supply end date; a country whose document has more to say about a
 * periodic supply - a flag, how often it is billed - adds it here, as the
 * request fields its own InvoiceRules take.
 */
interface RecurringSupply
{
    /**
     * One period's invoice, as a request to create it gives it, with what
     * the country adds for a supply billed at this frequency.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function periodic(array $payload, ScheduleFrequency $frequency): array;
}
