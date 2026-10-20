<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\DTOs;

use Carbon\CarbonImmutable;

/**
 * One line as a statement or settlement report printed it, before it is
 * kept: money in (credit) or out (debit), always a positive amount with
 * two decimals ("1150.00").
 *
 * For a card settlement line, amount is the gross the customer paid, fee
 * what the gateway kept, and gatewayReference the gateway's transaction id.
 */
final readonly class ParsedLine
{
    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    public function __construct(
        public CarbonImmutable $bookedOn,
        public string $direction,
        public string $amount,
        public ?string $currency = null,
        public ?CarbonImmutable $valueOn = null,
        public ?string $reference = null,
        public ?string $bankReference = null,
        public ?string $counterpartyName = null,
        public ?string $counterpartyAccount = null,
        public ?string $description = null,
        public ?string $gatewayReference = null,
        public ?string $fee = null,
    ) {}
}
