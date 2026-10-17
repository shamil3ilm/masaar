<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;

/**
 * A charge: on the whole document (IBG-21), with the tax category it is
 * made at (IBT-102, IBT-103) and, when exempt, its exemption (IBT-198,
 * IBR-169-AE); or on one line (IBG-28), taxed as the line is, with no
 * category of its own.
 *
 * Its reason in words and as a UNCL 7161 code (IBT-104/IBT-105,
 * IBT-144/IBT-145); a percentage and its base when it is one, which travel
 * together (ALIGNED-IBRP-058) and multiply to the amount (IBR-146-AE).
 */
final readonly class PintCharge
{
    public function __construct(
        public string $amount,
        public string $reasonCode,
        public string $reason,
        public ?string $percent = null,
        public ?string $base = null,
        public ?PintTaxCategory $category = null,
        public ?string $rate = null,
        public ?string $exemptionCode = null,
    ) {}
}
