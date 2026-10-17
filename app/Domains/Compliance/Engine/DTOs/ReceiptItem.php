<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * One line of a receipt: what was sold, then how many at what price and
 * what that comes to.
 */
final readonly class ReceiptItem
{
    /**
     * @param  string  $description  in the document's language, on its own line
     * @param  string  $quantity  with its unit, in both languages: "2 kg / كغ"
     * @param  string|null  $discount  the line's own discount, when it has one
     * @param  list<ReceiptRow>  $details  further amounts the country requires on the line
     * @param  list<string>  $notes  such as why no tax is charged on it
     */
    public function __construct(
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $total,
        public ?string $discount = null,
        public array $details = [],
        public array $notes = [],
    ) {}
}
