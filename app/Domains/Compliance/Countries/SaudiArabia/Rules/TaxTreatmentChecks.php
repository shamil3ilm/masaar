<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator;

/**
 * The VAT treatment of a Saudi draft's lines and document charges, checked
 * after its request rules: category, exemption reason and rate together.
 */
final class TaxTreatmentChecks
{
    /**
     * Each line's VAT treatment as ZATCA reads it, from its SDK's
     * Schematrons (CEN-EN16931-UBL.xsl and 20210819_ZATCA_E-invoice_
     * Validation_Rules.xsl):
     *
     *   BR-KSA-CL-04  a zero-rated or exempt line names its reason, from that
     *                 category's codes; BR-KSA-24 an out-of-scope one too.
     *   BR-S-05       a standard-rated line has a rate above zero, and
     *   BR-Z-05,      a zero-rated or exempt line a rate of zero; an
     *   BR-E-05       out-of-scope one charges nothing either (BR-O-09).
     *
     * A mixed basket - zero-rated medicines and standard-rated cosmetics on
     * one pharmacy receipt - is two categories, and every rule holds. Two
     * reasons in one category are not (oneReasonPerCategory()). A line whose
     * category is not yet known is left to the drafter.
     */
    public static function lineTreatments(Validator $validator, Request $request): void
    {
        $errors = $validator->errors();

        foreach ((array) $request->input('lines', []) as $index => $line) {
            if (! is_array($line) || blank($line['tax_category'] ?? null) || $errors->has("lines.{$index}.tax_category") || $errors->has("lines.{$index}.exempt_code")) {
                continue;
            }

            $category = (string) $line['tax_category'];
            $code = $line['exempt_code'] ?? null;
            $standard = $category === FatooraConfig::TAX_CATEGORY_STANDARD;

            if (! $standard && blank($code)) {
                $errors->add("lines.{$index}.exempt_code", __('Exemption code is required for line :line with tax category :category.', ['line' => $index + 1, 'category' => $category]));

                continue;
            }

            if (filled($code) && (FatooraConfig::CODE_CATEGORIES[$code] ?? null) !== $category) {
                $errors->add("lines.{$index}.exempt_code", __('Line :line: :code is a reason for tax category :expected, not :category (BR-KSA-CL-04).', [
                    'line' => $index + 1, 'code' => $code, 'expected' => FatooraConfig::CODE_CATEGORIES[$code] ?? '-', 'category' => $category,
                ]));

                continue;
            }

            self::lineRate($validator, $index, $line, $standard);
        }
    }

    /**
     * BR-S-05, BR-Z-05 and BR-E-05, and nothing charged out of scope.
     *
     * @param  array<string, mixed>  $line
     */
    private static function lineRate(Validator $validator, int|string $index, array $line, bool $standard): void
    {
        $rate = $line['tax_rate'] ?? null;

        if (! is_numeric($rate) || $validator->errors()->has("lines.{$index}.tax_rate")) {
            return;
        }

        if ($standard !== (bccomp((string) $rate, '0', 2) > 0)) {
            $validator->errors()->add("lines.{$index}.tax_rate", $standard
                ? __('Line :line: a standard-rated line has a rate above zero (BR-S-05).', ['line' => $index + 1])
                : __('Line :line: a line that is zero-rated, exempt or out of scope has a rate of zero (BR-Z-05, BR-E-05).', ['line' => $index + 1]));
        }
    }

    /**
     * A document charge's rate is its category's: above zero when standard
     * (BR-S-07), zero otherwise (BR-Z-07, BR-E-07, BR-KSA-13). One
     * that is not standard-rated names the exemption it falls under, from
     * that category's codes (BR-KSA-CL-04), which its breakdown carries when
     * no line is in that category (BR-KSA-23, BR-KSA-24, BR-KSA-69).
     */
    public static function chargeCategories(Validator $validator, Request $request): void
    {
        $errors = $validator->errors();

        foreach ((array) $request->input('charges', []) as $index => $charge) {
            if (! is_array($charge) || $errors->has("charges.{$index}.tax_category") || $errors->has("charges.{$index}.tax_rate")) {
                continue;
            }

            $category = (string) $charge['tax_category'];
            $rate = (string) $charge['tax_rate'];
            $standard = $category === FatooraConfig::TAX_CATEGORY_STANDARD;

            if ($standard !== (bccomp($rate, '0', 2) > 0)) {
                $errors->add("charges.{$index}.tax_rate", $standard
                    ? __('A standard-rated charge has a rate above zero.')
                    : __('A charge that is zero-rated, exempt or out of scope has a rate of zero.'));
            }

            $code = $charge['exempt_code'] ?? null;

            if (! $standard && (blank($code) || FatooraConfig::taxCategoryFor($code, 0) !== $category)) {
                $errors->add("charges.{$index}.exempt_code", __('A charge in tax category :category names its exemption code from that category (BR-KSA-CL-04).', ['category' => $category]));
            }
        }
    }

    /**
     * One exemption reason per tax category on an invoice.
     *
     * The XML Implementation Standard v1.2 gives one VAT breakdown to each
     * category and rate (§8.4, §9.6), and the reason (BT-120, BT-121) is
     * stated only there, never on a line. A second reason in the category
     * would be dropped from the document. A breakdown per reason instead
     * draws BR-Z-08 or BR-E-08 from ZATCA's validator, each breakdown's base
     * being checked against every line of the category
     * (ScenarioConformanceTest). The lines and document charges are read
     * together, as the breakdown reads them; refused in both languages, as
     * ArabicText refuses.
     *
     * A reason the Standard gives no text for (VATEX-SA-OOS, DIPLOMAT,
     * DUTYFREE) is worded by the seller, and its words are stated once in the
     * breakdown too (BT-120; BR-KSA-24, BR-KSA-83 for out of scope): two
     * wordings of it would lose one the same way. The same wording on
     * several lines is one reason.
     */
    public static function oneReasonPerCategory(Validator $validator, Request $request): void
    {
        $errors = $validator->errors();
        $reasons = [];
        $entries = [
            ...collect((array) $request->input('lines', []))->map(fn ($line, $index) => [$line, "lines.{$index}.exempt_code"])->values()->all(),
            ...collect((array) $request->input('charges', []))->map(fn ($charge, $index) => [$charge, "charges.{$index}.exempt_code"])->values()->all(),
        ];

        foreach ($entries as [$entry, $field]) {
            $code = is_array($entry) ? ($entry['exempt_code'] ?? null) : null;

            if (! is_string($code) || $code === '' || $errors->has($field)) {
                continue;
            }

            $category = filled($entry['tax_category'] ?? null) ? (string) $entry['tax_category'] : FatooraConfig::taxCategoryFor($code, 0);
            // A reason the seller words (no official text: OOS, DIPLOMAT,
            // DUTYFREE) is its code and its words; an official one, its code.
            $words = isset(FatooraConfig::EXEMPTION_REASONS_EN[$code]) ? null : self::words($entry['exempt_reason'] ?? null);
            $first = $reasons[$category] ??= ['code' => $code, 'words' => $words];

            if ($first['code'] !== $code && self::careAndMedicines($first['code'], $code)) {
                // A clinic's visit and its medicines: the citizen's zero
                // rating names the patient (BR-KSA-49), the medicines' does
                // not, and the two are one category. So two invoices.
                self::refuse($errors, $field, 'A citizen\'s private healthcare or education (:citizen) and zero-rated medicines (VATEX-SA-35) cannot share an invoice: tax category Z takes one exemption reason per invoice. Keep the care on this one and issue the medicines on a separate invoice (ZATCA XML Implementation Standard §8.4).', ['citizen' => in_array($first['code'], FatooraConfig::NAMED_BUYER_EXEMPTIONS, true) ? $first['code'] : $code]);
            } elseif ($first['code'] !== $code) {
                self::refuse($errors, $field, 'Tax category :category takes one exemption reason per invoice, but this invoice gives :codes: put them on separate invoices (ZATCA XML Implementation Standard §8.4).', ['category' => $category, 'codes' => $first['code'].', '.$code]);
            } elseif ($first['words'] !== $words) {
                self::refuse($errors, $field, ':code is stated with one wording per invoice, but this invoice words it twice: put them on separate invoices or word them the same (ZATCA XML Implementation Standard §8.4).', ['code' => $code]);
            }
        }
    }

    /** A citizen's zero rating (VATEX-SA-HEA, -EDU) beside the medicines' (VATEX-SA-35). */
    private static function careAndMedicines(string $one, string $other): bool
    {
        $codes = [$one, $other];

        return in_array('VATEX-SA-35', $codes, true)
            && array_intersect($codes, FatooraConfig::NAMED_BUYER_EXEMPTIONS) !== [];
    }

    /** A seller's wording as the breakdown would state it: trimmed, its spaces single. */
    private static function words(mixed $text): ?string
    {
        $words = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return $words === '' ? null : $words;
    }

    /**
     * A refusal in English and Arabic whatever the caller's language, as
     * ArabicText refuses.
     *
     * @param  array<string, string>  $replace
     */
    private static function refuse(MessageBag $errors, string $field, string $message, array $replace): void
    {
        // On an Arabic page, the Arabic alone (ArabicText::message).
        $errors->add($field, str_starts_with(App::getLocale(), 'ar')
            ? __($message, $replace, 'ar')
            : __($message, $replace, 'en').' / '.__($message, $replace, 'ar'));
    }
}
