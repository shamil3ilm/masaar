<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

/**
 * How a card payment ended, as the provider tells it: taken, refused, or
 * not decided yet; the provider's transaction id; the amount and currency
 * it took; and the fee it keeps.
 */
final readonly class CardCharge
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public function __construct(
        public string $status,
        public ?string $transactionId,
        public string $amount,
        public string $currency,
        public string $fee = '0.00',
        public ?string $failure = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
