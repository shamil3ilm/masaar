<?php

declare(strict_types=1);

namespace App\Http\Listing;

/**
 * A set of filters someone named, to come back to: its name and the query
 * string that asks for it.
 */
final readonly class SavedView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $query,
    ) {}
}
