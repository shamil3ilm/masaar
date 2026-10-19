<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

/**
 * One piece of a home page section's text, written in English and Arabic.
 *
 * A line is a heading or a button's label; a paragraph may run to several
 * lines and mark words bold (PlainText). A field may hold less than its
 * kind allows ($limit): a search result shows only so much of a title.
 */
final readonly class SectionField
{
    public const LINE_MAX = 200;

    public const PARAGRAPH_MAX = 1000;

    public function __construct(
        public string $name,
        public string $label,
        public bool $paragraph = false,
        public ?int $limit = null,
    ) {}

    public function max(): int
    {
        return $this->limit ?? ($this->paragraph ? self::PARAGRAPH_MAX : self::LINE_MAX);
    }
}
