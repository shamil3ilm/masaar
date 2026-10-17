<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * One captioned value on a receipt: a number, a date, an amount.
 */
final readonly class ReceiptRow
{
    /**
     * @param  bool  $strong  printed heavier: the amount due
     */
    public function __construct(
        public PrintLabel $label,
        public string $value,
        public bool $strong = false,
    ) {}
}
