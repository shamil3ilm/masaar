<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * One box of a VAT return draft: its number on the form, what it is, its
 * amount, adjustment and VAT where the form asks for each (null where it
 * does not), and the documents whose amounts make it up. A box that is
 * another's sum, or typed in, has none.
 */
final readonly class VatReturnBox
{
    /**
     * @param  list<VatReturnEntry>  $entries
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?float $amount,
        public ?float $adjustment,
        public ?float $vat,
        public array $entries = [],
        public bool $traced = true,
    ) {}
}
