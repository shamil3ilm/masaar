<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Translation\Translator;

/**
 * The translator, with :app in any line standing for the product's name in
 * that line's language (Brand::name()), so no string has to spell the name
 * and an Arabic line shows the Arabic one.
 *
 * A caller that passes its own 'app' keeps it: the website's catalogue
 * passes ':app' back, so the placeholder survives into the defaults an
 * admin edits and is put in only as the page is drawn.
 */
final class BrandTranslator extends Translator
{
    public static function from(Translator $translator): self
    {
        $branded = new self($translator->getLoader(), $translator->getLocale());
        $branded->setFallback($translator->getFallback());
        // Two-form lines read right in Arabic (PluralSelector).
        $branded->setSelector(new PluralSelector);

        return $branded;
    }

    /**
     * @param  string  $key
     * @param  string|null  $locale
     * @param  bool  $fallback
     * @return string|array<mixed>
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $replace['app'] ??= Brand::name($locale ?: $this->locale);

        return parent::get($key, $replace, $locale, $fallback);
    }
}
