<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

/**
 * A card payment started at the provider: its id there, and the page the
 * customer is sent to.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $redirectUrl,
    ) {}
}
