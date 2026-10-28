<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use Illuminate\Support\Facades\App;

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
 * and the company page, SellerRules), each line's description, the buyer's
 * Arabic name on a standard invoice and the notes against one, and every
 * free text the invoice prints when it is given - the notes, the reason for
 * a credit or debit note (KSA-10), the payment terms (KSA-22) and the reason
 * for each charge, the document's and a line's. Arabic means Arabic letters:
 * a Latin name typed into the Arabic field is not a translation.
 *
 * What is not free text is not checked here: references and identifiers
 * (order, contract, buyer reference, VAT and CR numbers), dates, amounts and
 * addresses, which the Guideline for Tax Invoicing and Records lets be
 * written in Latin characters as names of places and numbers; and coded
 * values, which print their Arabic beside them - an exemption code ZATCA's
 * own Arabic (XML Implementation Standard p32-33, FatooraConfig::
 * EXEMPTION_REASONS_AR), a unit code its Arabic unit (UnitCode::printed).
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

        if (filled($draft['adjustment_reason'] ?? null) && ! self::isArabic($draft['adjustment_reason'])) {
            $missing['adjustment_reason'] = self::message(__('Arabic reason required (ZATCA: the invoice must be in Arabic)', [], 'en'));
        }

        if (filled($draft['payment_terms'] ?? null) && ! self::isArabic($draft['payment_terms'])) {
            $missing['payment_terms'] = self::message(__('Arabic payment terms required (ZATCA: the invoice must be in Arabic)', [], 'en'));
        }

        foreach (self::chargeReasons($draft) as $field => $reason) {
            if (filled($reason) && ! self::isArabic($reason)) {
                $missing[$field] = self::message(__('Arabic charge reason required (ZATCA: the invoice must be in Arabic)', [], 'en'));
            }
        }

        return $missing;
    }

    /**
     * Every charge's reason, the document's and each line's, by field.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private static function chargeReasons(array $draft): array
    {
        $reasons = [];

        foreach (array_values((array) ($draft['charges'] ?? [])) as $index => $charge) {
            if (is_array($charge)) {
                $reasons["charges.{$index}.reason"] = $charge['reason'] ?? null;
            }
        }

        foreach (array_values((array) ($draft['lines'] ?? [])) as $line => $row) {
            foreach (array_values(is_array($row) ? (array) ($row['charges'] ?? []) : []) as $index => $charge) {
                if (is_array($charge)) {
                    $reasons["lines.{$line}.charges.{$index}.reason"] = $charge['reason'] ?? null;
                }
            }
        }

        return $reasons;
    }

    /**
     * A refusal in both languages, whatever the caller's: an integrator
     * reads the English, the person at the till the Arabic. Given the
     * English, as __() returns it in English.
     *
     * A message that names its field (:Attribute) names it in each
     * language: the field's name from validation.attributes, else its key
     * in words.
     *
     * On an Arabic page, the Arabic alone: the person reading it reads
     * Arabic, and an English sentence beside it is the half-and-half page
     * the walk found.
     */
    public static function message(string $english, ?string $attribute = null): string
    {
        if (str_starts_with(App::getLocale(), 'ar')) {
            return self::arabicOnly($english, $attribute);
        }

        $arabic = trans($english, [], 'ar');

        if ($attribute !== null) {
            $key = 'validation.attributes.'.$attribute;
            $inEnglish = trans($key, [], 'en');
            $inEnglish = $inEnglish === $key ? ucfirst(str_replace('_', ' ', (string) preg_replace('/^.*\./', '', $attribute))) : $inEnglish;
            $inArabic = trans($key, [], 'ar');
            $english = str_replace(':Attribute', $inEnglish, $english);
            $arabic = str_replace(':Attribute', $inArabic === $key ? $inEnglish : $inArabic, $arabic);
        }

        return $arabic === $english ? $english : $english.' / '.$arabic;
    }

    private static function arabicOnly(string $english, ?string $attribute): string
    {
        $arabic = trans($english, [], 'ar');

        if ($attribute !== null) {
            $key = 'validation.attributes.'.$attribute;
            $name = trans($key, [], 'ar');
            $arabic = str_replace(':Attribute', $name === $key ? (string) trans('validation.attributes.'.preg_replace('/^.*\./', '', $attribute), [], 'ar') : $name, $arabic);
        }

        return $arabic;
    }

    private static function type(mixed $type): ?string
    {
        return $type instanceof \BackedEnum ? (string) $type->value : (is_string($type) ? $type : null);
    }
}
