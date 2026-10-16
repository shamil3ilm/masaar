<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\DTOs;

use App\Domains\Compliance\Peppol\Enums\PintTaxCategory;

/**
 * One VAT breakdown (IBG-23): a category at a rate.
 *
 * taxAmount is exact, not rounded: the UAE rounds at the document total
 * only (Guidelines v1.1, 12.2 item 5), and the schematron rounds the sum.
 */
final readonly class PintTaxSubtotal
{
    public function __construct(
        public PintTaxCategory $category,
        public ?string $rate,
        public string $taxableAmount,
        public string $taxAmount,
        public ?string $exemptionCode = null,
        public ?string $exemptionReason = null,
    ) {}
}
