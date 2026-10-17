<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;

/**
 * One invoice line, with its amounts already decided.
 *
 * Amounts are decimal strings. vatAed and totalAed are the line's VAT and
 * amount payable in dirhams whatever the document currency (BTAE-08,
 * BTAE-10); vatAed is null on an exempt line, which must not carry one
 * (IBR-163-AE).
 */
final readonly class PintLine
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $quantity,
        public string $unitCode,
        public string $netAmount,
        public string $price,
        public PintTaxCategory $category,
        public ?string $rate,
        public ?string $vatAed,
        public string $totalAed,
        public ?string $exemptionCode = null,
        public ?string $exemptionReason = null,
        public ?string $classificationCode = null,
        public ?string $standardItemId = null,
        public ?string $goodsType = null,
    ) {}
}
