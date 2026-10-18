<?php

declare(strict_types=1);

namespace App\Http\Listing;

/**
 * One active filter, as the list says it back: what narrows it, by how,
 * and the link that takes it away again.
 */
final readonly class Chip
{
    public function __construct(
        public string $label,
        public string $value,
        public string $removeUrl,
    ) {}
}
