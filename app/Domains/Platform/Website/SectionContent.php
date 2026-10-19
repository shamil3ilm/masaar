<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use Illuminate\Support\HtmlString;

/**
 * One section's text as a home page partial reads it: in the page's
 * language, the other language beside a heading, and a paragraph made safe
 * (PlainText). Nothing here returns markup an admin wrote.
 */
final readonly class SectionContent
{
    /**
     * @param  array<string, mixed>  $content  every field in both languages
     */
    public function __construct(
        public string $key,
        private array $content,
        private string $locale,
    ) {}

    /** A field in the page's language. */
    public function text(string $field): string
    {
        return $this->in($field, $this->locale);
    }

    /** A field in the other language: a section heading's quieter twin. */
    public function pair(string $field): string
    {
        return $this->in($field, $this->locale === 'ar' ? 'en' : 'ar');
    }

    /** A paragraph field, escaped, with its line breaks and bold kept. */
    public function html(string $field): HtmlString
    {
        return PlainText::html($this->text($field));
    }

    /** Any text from items(), made safe the same way. */
    public function rich(string $text): HtmlString
    {
        return PlainText::html($text);
    }

    /**
     * A list's items, each field in the page's language.
     *
     * @return list<array<string, string>>
     */
    public function items(string $list): array
    {
        return array_map(
            fn (array $item): array => array_map(fn (array $both): string => (string) ($both[$this->locale] ?? ''), $item),
            array_values((array) ($this->content[$list] ?? [])),
        );
    }

    private function in(string $field, string $locale): string
    {
        return (string) ($this->content[$field][$locale] ?? '');
    }
}
