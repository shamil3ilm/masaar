<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

use Carbon\CarbonImmutable;

/**
 * Money that arrived from a customer, as it is recorded: how much it is
 * credited with, in which currency, when, its reference on the statement,
 * the account it came into, what the bank kept, and the rate to the tax
 * currency that day for a payment in another currency.
 *
 * $allocations is how much of it goes to which invoice, by receivable id;
 * empty sets it against the customer's oldest open invoices in that
 * currency. Whatever is left becomes the customer's credit.
 */
final readonly class ReceivedPayment
{
    /**
     * @param  array<string, string>  $allocations  receivable id => amount
     */
    public function __construct(
        public string $amount,
        public string $currency,
        public CarbonImmutable $receivedOn,
        public ?string $reference = null,
        public ?string $bankAccount = null,
        public ?string $fee = null,
        public ?string $exchangeRate = null,
        public ?string $notes = null,
        public array $allocations = [],
    ) {}
}
