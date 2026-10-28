<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

/**
 * What a ZATCA rule code means, in a sentence an owner reads.
 *
 * The authority's warnings and refusals name a rule - "BR-KSA-63", "BR-KSA-
 * F-06" - and the portal said "check rule BR-KSA-63" with no hint of what
 * that is. Each rule this app's documents can meet has its meaning here, as
 * the rule is cited across the code (the comments beside each check); a rule
 * not listed is explained by its family. The authority's own words are kept
 * beside it wherever it is shown.
 */
final class RuleMeanings
{
    /** The first rule code in a message, such as BR-KSA-F-06 or BR-CO-15. */
    public static function code(string $raw): ?string
    {
        return preg_match('/\b(BR-(?:KSA-)?(?:EN16931-|DEC-|F-|CL-)?(?:[A-Z]{1,2}-)?\d+)\b/', $raw, $match) === 1 ? $match[1] : null;
    }

    /** The rule's meaning in the reader's language; by its family when it has none of its own. */
    public static function explain(string $code): string
    {
        $code = strtoupper($code);

        return match ($code) {
            'BR-KSA-03' => __('The invoice\'s unique identifier (UUID) is not in the right form.'),
            'BR-KSA-04' => __('The invoice is dated in the future. An invoice cannot be dated after today.'),
            'BR-KSA-05' => __('The kind of document is not one ZATCA accepts: a tax invoice, a credit note or a debit note.'),
            'BR-KSA-06' => __('The invoice\'s transaction type (standard or simplified, export, summary and so on) is not in the right form.'),
            'BR-KSA-07' => __('An export invoice cannot be self-billed.'),
            'BR-KSA-08' => __('Your company\'s identification on the invoice, such as its commercial registration, is missing or of the wrong type. Check it on the company page.'),
            'BR-KSA-09' => __('Your company\'s national address is incomplete: it needs street, building number, postal code, city and district. Complete it on the company page.'),
            'BR-KSA-11' => __('A line outside the scope of VAT must have a VAT rate of 0%.'),
            'BR-KSA-15' => __('A standard tax invoice needs the supply date: the date the goods or services were supplied.'),
            'BR-KSA-16' => __('The payment method is not one ZATCA accepts.'),
            'BR-KSA-17' => __('A credit or debit note must say why it was issued.'),
            'BR-KSA-18' => __('A line\'s VAT category must be standard, zero-rated, exempt or out of scope.'),
            'BR-KSA-19', 'BR-KSA-21' => __('A charge on the invoice needs its reason, as a code and in words.'),
            'BR-KSA-20', 'BR-KSA-22' => __('A charge on a line needs its reason, as a code and in words.'),
            'BR-KSA-23' => __('An exempt line needs its exemption reason.'),
            'BR-KSA-24' => __('A line outside the scope of VAT needs its reason.'),
            'BR-KSA-25' => __('The buyer\'s name is needed on a simplified invoice for private healthcare or education to a citizen.'),
            'BR-KSA-26' => __('The link to the previous invoice (its hash) is not what ZATCA expected. Contact support.'),
            'BR-KSA-27' => __('The invoice is missing its QR code.'),
            'BR-KSA-28', 'BR-KSA-30' => __('The invoice\'s digital signature is missing or not in the right form. Contact support.'),
            'BR-KSA-31' => __('A simplified invoice cannot be self-billed or an export. Issue those on a standard tax invoice.'),
            'BR-KSA-33', 'BR-KSA-34' => __('The invoice counter is missing or not a whole number above zero. Contact support.'),
            'BR-KSA-35' => __('A supply end date needs a supply start date.'),
            'BR-KSA-36' => __('The supply end date is before the supply start date.'),
            'BR-KSA-37' => __('The building number in your company\'s address must be 4 digits.'),
            'BR-KSA-39' => __('Your company\'s VAT number is missing.'),
            'BR-KSA-40' => __('Your company\'s VAT number must be 15 digits, starting and ending with 3.'),
            'BR-KSA-44' => __('The buyer\'s VAT number must be 15 digits, starting and ending with 3.'),
            'BR-KSA-46' => __('An export invoice must not carry the buyer\'s Saudi VAT number. Identify a buyer abroad another way.'),
            'BR-KSA-49' => __('Zero-rated private healthcare or education to a citizen needs the buyer\'s national ID.'),
            'BR-KSA-51' => __('A line\'s total with VAT does not equal its net amount plus its VAT.'),
            'BR-KSA-56' => __('A credit or debit note needs the number of the invoice it corrects.'),
            'BR-KSA-60' => __('A simplified invoice or note is missing its cryptographic stamp. Contact support.'),
            'BR-KSA-63' => __('The buyer\'s national address is incomplete. A Saudi buyer on a standard invoice needs street, building number, postal code, city and district.'),
            'BR-KSA-66' => __('The postal code in your company\'s address must be 5 digits.'),
            'BR-KSA-67' => __('A Saudi buyer\'s postal code must be 5 digits.'),
            'BR-KSA-68', 'BR-KSA-EN16931-02' => __('The VAT must be stated in Saudi riyals.'),
            'BR-KSA-69' => __('A zero-rated line needs its zero-rating reason.'),
            'BR-KSA-71' => __('A simplified summary invoice needs the buyer\'s name.'),
            'BR-KSA-72' => __('A simplified summary invoice needs the period it covers: a supply start and end date.'),
            'BR-KSA-73' => __('Only a final tax invoice can settle prepayments.'),
            'BR-KSA-74', 'BR-KSA-75', 'BR-KSA-82', 'BR-KSA-93' => __('A prepayment line does not match the prepayment invoice it settles.'),
            'BR-KSA-81' => __('A standard invoice needs the buyer\'s VAT number or, without one, another identifier such as the commercial registration.'),
            'BR-KSA-83' => __('A zero-rated, exempt or out-of-scope line needs its reason in words.'),
            'BR-KSA-84' => __('A standard-rated line must have the standard VAT rate.'),
            'BR-KSA-92' => __('An invoice to a government buyer needs the contract it falls under.'),
            'BR-KSA-F-04' => __('An amount on the invoice is negative. A discount cannot be larger than the lines.'),
            'BR-KSA-F-06' => __('A text on the invoice is too long: notes and payment terms take at most 1,000 characters, a purchase order or contract number at most 127.'),
            'BR-KSA-F-13' => __('The other identification number of the buyer or seller looks like a placeholder, such as a run of repeated or consecutive digits.'),
            'BR-KSA-CL-01' => __('The currency is not one ZATCA accepts.'),
            'BR-KSA-CL-04' => __('The exemption or zero-rating reason does not belong to the line\'s VAT category.'),
            'BR-KSA-CL-06' => __('A charge\'s reason code is not one ZATCA accepts.'),
            'BR-KSA-DEC-01' => __('An amount has more than two decimal places.'),
            'BR-KSA-EN16931-01' => __('The invoice\'s business process is not the one ZATCA expects. Contact support.'),
            'BR-KSA-EN16931-03', 'BR-KSA-EN16931-04', 'BR-KSA-EN16931-05' => __('A discount or charge given as a percentage does not match its amount.'),
            'BR-KSA-EN16931-11' => __('A line\'s net amount does not equal quantity times price, less its discount.'),
            'BR-06' => __('Your company\'s name is missing.'),
            'BR-16' => __('The invoice has no lines.'),
            'BR-53' => __('An invoice in another currency must state its VAT total in Saudi riyals, so it needs the exchange rate.'),
            'BR-S-05', 'BR-S-07' => __('A standard-rated line or charge must have a VAT rate above zero.'),
            'BR-Z-05', 'BR-Z-07', 'BR-E-05', 'BR-E-07', 'BR-O-09' => __('A zero-rated, exempt or out-of-scope line or charge must have a VAT rate of 0%.'),
            'BR-S-08', 'BR-S-09', 'BR-Z-08', 'BR-E-08', 'BR-CO-17' => __('The VAT for one category does not add up from its lines, usually by a rounding difference.'),
            'BR-CO-10', 'BR-CO-13', 'BR-CO-14', 'BR-CO-15' => __('The invoice totals do not add up from its lines, discounts, charges and VAT.'),
            default => self::family($code),
        };
    }

    private static function family(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'BR-KSA-F-') => __('A value on the invoice is not in the form ZATCA expects, such as a text that is too long.'),
            str_starts_with($code, 'BR-KSA-CL-'), str_starts_with($code, 'BR-CL-') => __('A code on the invoice is not one from ZATCA\'s lists.'),
            preg_match('/^BR-(S|Z|E|O|CO|DEC)-/', $code) === 1 => __('An amount or VAT figure on the invoice does not add up.'),
            default => __('Part of the invoice does not meet one of ZATCA\'s rules.'),
        };
    }
}
