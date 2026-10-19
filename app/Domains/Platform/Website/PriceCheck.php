<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

/**
 * Whether a text names a price: an amount next to a currency, in either
 * language.
 *
 * The website publishes no prices - a plan is agreed with each customer
 * (the owner's billing decision) - so a section's text that states one is
 * refused rather than trusted to be right. A number alone is not a price
 * ("four steps", "15% VAT"); a number beside a currency code, sign or name
 * is: "SAR 99", "99 SAR", "$49", "49 dollars", "٩٩ ريال", "99 ر.س".
 */
final class PriceCheck
{
    /** Currency codes, signs and names, each matched as a whole word. */
    private const CURRENCIES = '(?:SAR|AED|USD|EUR|GBP|KWD|QAR|BHD|OMR|EGP|INR|SR|Dhs?|riyals?|riyal|rials?|dirhams?|dollars?|euros?|pounds?'
        .'|ريال(?:ات|اً|ا)?|ر\.\s?س\.?|درهم|دراهم|د\.\s?إ\.?|دولار(?:ات)?|يورو|جنيه(?:ات)?)';

    private const SIGNS = '[$€£¥₹﷼]';

    /** Western and Arabic-Indic digits, with their separators. */
    private const AMOUNT = '[0-9٠-٩۰-۹](?:[0-9٠-٩۰-۹.,٬٫\s]*[0-9٠-٩۰-۹])?';

    public static function namesPrice(string $text): bool
    {
        $before = '/(?<![\p{L}])'.self::CURRENCIES.'\s*'.self::AMOUNT.'/iu';
        $after = '/'.self::AMOUNT.'\s*'.self::CURRENCIES.'(?![\p{L}])/iu';
        $sign = '/'.self::SIGNS.'\s*'.self::AMOUNT.'|'.self::AMOUNT.'\s*'.self::SIGNS.'/u';

        return preg_match($before, $text) === 1 || preg_match($after, $text) === 1 || preg_match($sign, $text) === 1;
    }
}
