<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * One line of a receipt: what was sold, how many, at what price.
 */
final readonly class ReceiptItem
{
    /**
     * @param  string|null  $secondary  the description in the other language, when both were given
     * @param  string  $quantity  with its unit: "2 hours"
     * @param  list<ReceiptRow>  $details  further amounts the country requires on the line
     * @param  list<string>  $notes  such as why no tax is charged on it
     */
    public function __construct(
        public string $description,
        public ?string $secondary,
        public string $quantity,
        public string $unitPrice,
        public string $total,
        public array $details = [],
        public array $notes = [],
    ) {}
}
