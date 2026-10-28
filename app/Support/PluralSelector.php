<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Translation\MessageSelector;

/**
 * Which form of a counted line to show, for lines written in two forms.
 *
 * Arabic has six plural forms, and the framework numbers them so: zero, one,
 * two, a few, many, other. The Arabic lines here are written in two, one and
 * many, as the English beside them are - so the framework took the second
 * form for one ("1 فواتير") and fell back to the first for every other
 * number ("فاتورة واحدة" for five). A line in two forms is chosen as English
 * chooses: the first for one, the second otherwise. A line with its own
 * ranges ({1}, [2,*]) or more forms is chosen as written.
 */
final class PluralSelector extends MessageSelector
{
    public function choose($line, $number, $locale)
    {
        if (count(explode('|', $line)) === 2 && preg_match('/^\s*[{\[]/', $line) !== 1) {
            return parent::choose($line, $number, 'en');
        }

        return parent::choose($line, $number, $locale);
    }
}
