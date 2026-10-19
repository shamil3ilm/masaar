<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

use Carbon\CarbonImmutable;

/**
 * The invoice the home page draws: made up, and labelled a sample wherever
 * it is shown.
 *
 * Amounts are two-decimal strings, as an invoice prints them.
 */
final readonly class SampleInvoice
{
    /**
     * @param  array<string, string>  $lines  description => amount before tax
     */
    public function __construct(
        public string $sellerName,
        public string $number,
        public CarbonImmutable $issuedAt,
        public array $lines,
        public string $tax,
        public string $total,
    ) {}
}
