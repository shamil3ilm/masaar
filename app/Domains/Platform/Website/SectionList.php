<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

/**
 * A list of items in a section - features, steps, questions - each item the
 * same fields in both languages.
 *
 * A fixed list keeps its count: each of its items is tied to something in
 * code (the "Need something else?" asks each open the contact form on their
 * own topic), so only their words change.
 */
final readonly class SectionList
{
    /**
     * @param  list<SectionField>  $fields
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $fields,
        public int $min = 1,
        public int $max = 8,
        public bool $fixed = false,
    ) {}
}
