<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\DTOs;

use Carbon\CarbonImmutable;

/**
 * A statement or settlement report as read from its file: the account it
 * says it is for, its currency, its own id, the period and balances where
 * it states them, and its lines in order.
 */
final readonly class ParsedStatement
{
    /**
     * @param  list<ParsedLine>  $lines
     */
    public function __construct(
        public array $lines,
        public ?string $accountIdentifier = null,
        public ?string $currency = null,
        public ?string $statementReference = null,
        public ?CarbonImmutable $periodFrom = null,
        public ?CarbonImmutable $periodTo = null,
        public ?string $openingBalance = null,
        public ?string $closingBalance = null,
    ) {}
}
