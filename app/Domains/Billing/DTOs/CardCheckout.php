<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

/**
 * A card payment to start: how much, in which currency, what it is for,
 * the reference the provider keeps with it, and where the customer comes
 * back to.
 */
final readonly class CardCheckout
{
    public function __construct(
        public string $amount,
        public string $currency,
        public string $reference,
        public string $description,
        public string $returnUrl,
    ) {}
}
