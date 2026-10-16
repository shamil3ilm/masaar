<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\DTOs;

use App\Domains\Compliance\Peppol\Enums\PintTaxCategory;

/**
 * A document-level allowance (IBG-20): the invoice's discount, or the share
 * of it that falls on one tax category. Each allowance names exactly one.
 */
final readonly class PintAllowance
{
    public function __construct(
        public string $amount,
        public PintTaxCategory $category,
        public ?string $rate,
        public ?string $exemptionCode = null,
    ) {}
}
