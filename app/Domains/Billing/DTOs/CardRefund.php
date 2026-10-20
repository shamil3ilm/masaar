<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

/**
 * A refund to a card, as the provider tells it: done or refused, and its
 * id at the provider.
 */
final readonly class CardRefund
{
    public function __construct(
        public bool $refunded,
        public ?string $refundId,
        public ?string $failure = null,
    ) {}
}
