<?php

declare(strict_types=1);

namespace App\Http\Listing;

/**
 * One order a list can be read in: what it is called, the column it orders
 * by, and which way it goes first - newest, largest and latest first; names
 * and numbers from the start.
 */
final readonly class Sort
{
    public function __construct(
        public string $label,
        public string $column,
        public string $direction = 'desc',
    ) {}
}
