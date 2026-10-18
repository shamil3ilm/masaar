<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

/**
 * The Arabic a Saudi invoice has to carry, and the words a refusal is
 * given in.
 *
 * ZATCA's E-Invoicing Detailed Guidelines (v2, May 2023):
 *
 *   p60  "As per Article 53 of VAT Regulations, invoices must be in Arabic.
 *         The technical aspects of XML will be in English, however, the data
 *         for invoices (that will be visible once XML is visualized) must be
 *         in Arabic. Other languages can be present on the Invoice as well."
 *   p63  "Any information contained in the human readable form of the Tax
 *         Invoice must be in Arabic including notes. Any additional
 *         translation is also allowed."
 *   p28, p36  the human readable format "can be presented provided that it
 *         is in Arabic (in addition to any other language)".
 *
 * The XML Implementation Standard (v1.2, May 2023) and the BR-KSA rules of
 * the SDK's Schematron add no rule on the language of text: the Standard
 * asks only for Arabic numerals (p12) and gives the Arabic of each
 * exemption reason (p32), which the templates print already. So this is
 * checked here, on the draft, and nothing in the signed XML changes.
 *
 * The owner's decision, from those pages: the seller's Arabic name (sign-up
 * and the company page, SellerRules), each line's description, the notes
 * when there are any, and the buyer's Arabic name on a standard invoice and
 * the notes against one. Arabic means Arabic letters: a Latin name typed
 * into the Arabic field is not a translation.
 */
final class ArabicText
{
    /** At least one Arabic letter. */
    private const ARABIC_LETTER = '/(?=\p{Arabic})\p{L}/u';

    public static function isArabic(mixed $text): bool
    {
        return is_string($text) && preg_match(self::ARABIC_LETTER, $text) === 1;
    }

    /**
     * What a draft lacks, by the request's own field names.
     *
     * @param  array<string, mixed>  $draft  type, notes, buyer_name_ar and lines[].description_ar, as a request sends them
     * @return array<string, string>
     */
    public static function missing(array $draft): array
    {
        $missing = [];

        foreach (array_values((array) ($draft['lines'] ?? [])) as $index => $line) {
            if (is_array($line) && ! self::isArabic($line['description_ar'] ?? null)) {
                $missing["lines.{$index}.description_ar"] = self::message(__('Arabic description required (ZATCA: the invoice must be in Arabic)', [], 'en'));
            }
        }

        if (filled($draft['notes'] ?? null) && ! self::isArabic($draft['notes'])) {
            $missing['notes'] = self::message(__('Arabic notes required (ZATCA: notes on the invoice must be in Arabic too)', [], 'en'));
        }

        if (self::type($draft['type'] ?? null) === 'standard' && ! self::isArabic($draft['buyer_name_ar'] ?? null)) {
            $missing['buyer_name_ar'] = self::message(__('Arabic buyer name required (ZATCA: the invoice must be in Arabic)', [], 'en'));
        }

        return $missing;
    }

    /**
     * A refusal in both languages, whatever the caller's: an integrator
     * reads the English, the person at the till the Arabic. Given the
     * English, as __() returns it in English.
     */
    public static function message(string $english): string
    {
        $arabic = trans($english, [], 'ar');

        return $arabic === $english ? $english : $english.' / '.$arabic;
    }

    private static function type(mixed $type): ?string
    {
        return $type instanceof \BackedEnum ? (string) $type->value : (is_string($type) ? $type : null);
    }
}
