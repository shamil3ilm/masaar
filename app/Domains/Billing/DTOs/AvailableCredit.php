<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

use Carbon\CarbonImmutable;

/**
 * Credit an organization can use on its next invoice: $amount in $currency
 * (kind amount), or $percent off (kind percent, no currency). $source and
 * $sourceId name what granted it - a referral reward - for the invoice line
 * to say.
 */
final readonly class AvailableCredit
{
    public const AMOUNT = 'amount';

    public const PERCENT = 'percent';

    public function __construct(
        public string $id,
        public string $organizationId,
        public string $kind,
        public ?string $amount,
        public ?string $percent,
        public ?string $currency,
        public string $description,
        public string $source,
        public ?string $sourceId,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
