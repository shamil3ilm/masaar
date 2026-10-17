<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A block of a receipt about one party or the document itself.
 */
final readonly class ReceiptSection
{
    /**
     * @param  PrintLabel|null  $heading  whose block it is: the seller, the buyer
     * @param  string|null  $name  the party's name, printed first and heavier
     * @param  list<ReceiptRow>  $rows  its numbers and dates
     * @param  list<string>  $lines  free text after them, such as an address
     */
    public function __construct(
        public ?PrintLabel $heading,
        public ?string $name,
        public array $rows,
        public array $lines = [],
    ) {}
}
