<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

/**
 * Where a section stands: shown or not, its place, and whether its text is
 * an admin's or the default.
 */
final readonly class SectionState
{
    public function __construct(
        public SectionDefinition $definition,
        public bool $enabled,
        public int $position,
        public bool $edited,
    ) {}
}
