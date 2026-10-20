<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

use Carbon\CarbonImmutable;

/**
 * An invoice to a customer, as an admin enters it: what it is for in both
 * languages, the agreed amount before VAT - there is no price list - and,
 * for a subscription, the period it covers.
 *
 * $exchangeRate is the rate to the tax currency typed for this invoice, for
 * one in another currency; left out, the daily rate table answers where
 * that is the source.
 */
final readonly class ReceivableDraft
{
    public function __construct(
        public string $description,
        public string $descriptionAr,
        public string $amount,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
        public ?string $exchangeRate = null,
        public ?string $licenseId = null,
        public bool $applyCredit = true,
    ) {}
}
