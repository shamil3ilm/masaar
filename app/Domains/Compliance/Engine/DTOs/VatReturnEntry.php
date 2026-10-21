<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * One document's share of a VAT return box: a sale (an issued invoice or
 * note) or a purchase (a supplier's document in Books), signed, in the
 * return's currency.
 */
final readonly class VatReturnEntry
{
    public const SALE = 'sale';

    public const PURCHASE = 'purchase';

    public function __construct(
        public string $kind,
        public string $id,
        public string $number,
        public string $date,
        public string $party,
        public float $amount,
        public float $adjustment,
        public float $vat,
    ) {}
}
