<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use Illuminate\Support\HtmlString;

/**
 * What an admin wrote for the public website, made safe to show.
 *
 * Plain text, never HTML: everything is escaped first, so a tag typed into a
 * section is shown as the characters typed and never runs. Two things are
 * then given their meaning, on the escaped text, so neither can open a door
 * the escaping closed: a line break is kept, and **words between double
 * asterisks** are bold.
 */
final class PlainText
{
    /** **bold**: no asterisk or line break inside, at least one character. */
    private const BOLD = '/\*\*([^*\r\n]+)\*\*/u';

    public static function html(string $text): HtmlString
    {
        $escaped = e(str_replace(["\r\n", "\r"], "\n", trim($text)));
        $bold = (string) preg_replace(self::BOLD, '<strong class="font-semibold">$1</strong>', $escaped);

        return new HtmlString(nl2br($bold, false));
    }

    /** The text as it reads, with the bold marks taken out: for a meta tag or a single line. */
    public static function flat(string $text): string
    {
        return trim((string) preg_replace(['/\s+/u', self::BOLD], [' ', '$1'], $text));
    }
}
