<?php

declare(strict_types=1);

namespace App\Http\Listing;

use Illuminate\Contracts\Database\Query\Expression;

/**
 * One order a list can be read in: what it is called, the column it orders
 * by, and which way it goes first - newest, largest and latest first; names
 * and numbers from the start. The column may be an expression, such as an
 * amount in one currency across invoices in several.
 */
final readonly class Sort
{
    public function __construct(
        public string $label,
        public string|Expression $column,
        public string $direction = 'desc',
    ) {}
}
