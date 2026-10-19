<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A tax category an invoice line can be in, as the country's code list names
 * it: standard, zero-rated, exempt, out of scope, and whatever else the
 * country has.
 *
 * $rate is the rate a line in it states when it gives none - the standard
 * rate, zero, or null where the category states no rate at all. $taxed is
 * whether the invoice charges VAT on it: a category can state a rate and
 * charge none, as a reverse charge does.
 */
final readonly class VatCategory
{
    public function __construct(
        public string $code,
        public string $label,
        public ?string $rate,
        public bool $taxed = false,
    ) {}
}
