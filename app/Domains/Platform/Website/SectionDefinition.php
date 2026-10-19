<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

/**
 * One section of the home page: what an admin may write in it, and how it
 * starts.
 *
 * A pinned section is always shown, first: the hero is the page's heading
 * and its first screen. Every other section can be turned off and moved.
 */
final readonly class SectionDefinition
{
    /**
     * @param  list<SectionField>  $fields
     * @param  list<SectionList>  $lists
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public array $fields,
        public array $lists = [],
        public bool $pinned = false,
        public bool $enabledByDefault = true,
    ) {}
}
