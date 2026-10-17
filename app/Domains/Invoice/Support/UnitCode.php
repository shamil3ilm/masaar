<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

/**
 * What a quantity is counted in.
 *
 * ZATCA's data dictionary says units "are recommended to be from UN/ECE
 * Recommendation 20, Revision 16 (2020)". Peppol BIS Billing 3.0 goes
 * further and requires it, adding the packaging codes from Recommendation 21
 * with an X prefix so they cannot collide with Recommendation 20 values.
 *
 * Four of the codes this application used are in neither list:
 *
 *   PCE  was the default on every line that did not name a unit - written
 *        into InvoiceDrafter, DocumentBuilder, XmlBuilder twice, FtaService
 *        and the sample set. It is not a Recommendation 20 code. "Piece" is
 *        H87.
 *   BX   is Recommendation 21's box, which Peppol writes XBX.
 *   PK   is Recommendation 21's package, which Peppol writes XPK.
 *   CT   is Recommendation 21's carton, which Peppol writes XCT.
 *
 * ZATCA's schematron does not check the code against any list - the only
 * EN16931 rule on it is that the attribute is at most 127 characters - so
 * every invoice this platform has filed passed with a unit code ZATCA itself
 * recommends against. On the UAE's Peppol-based path it would not have.
 *
 * LEGACY translates the four rather than rejecting them, so an ERP already
 * sending PCE keeps working and still produces a conformant document. The
 * portal offers only conformant codes.
 *
 * Already-issued lines are deliberately not rewritten. Their signed XML is
 * what the authority holds and what the hash chain covers; changing the row
 * underneath would make the two disagree, which is worse than an unfashionable
 * unit code.
 *
 * Every code below was checked against the published list rather than
 * remembered. UnitCodeTest pins the four that are not in it.
 */
final class UnitCode
{
    /**
     * A countable thing, where nothing more specific was said.
     *
     * H87 is Recommendation 20's "piece", which is what PCE was meant to be.
     */
    public const DEFAULT = 'H87';

    /**
     * Grouped, because a flat list of forty codes is a worse dropdown than
     * seven short ones. The groups are for reading only - the code is what
     * goes in the XML.
     *
     * @var array<string, array<string, string>> group => code => label
     */
    public const GROUPS = [
        'Count' => [
            'H87' => 'Piece',
            'EA' => 'Each',
            'C62' => 'Unit',
            'SET' => 'Set',
        ],
        'Weight' => [
            'KGM' => 'Kilogram',
            'GRM' => 'Gram',
            'MGM' => 'Milligram',
            'TNE' => 'Tonne',
            'LBR' => 'Pound',
            'ONZ' => 'Ounce',
        ],
        'Volume' => [
            'LTR' => 'Litre',
            'MLT' => 'Millilitre',
            'MTQ' => 'Cubic metre',
        ],
        'Length' => [
            'MTR' => 'Metre',
            'CMT' => 'Centimetre',
            'MMT' => 'Millimetre',
            'KMT' => 'Kilometre',
            'YRD' => 'Yard',
            'SMI' => 'Mile',
        ],
        'Area' => [
            'MTK' => 'Square metre',
        ],
        'Time' => [
            'HUR' => 'Hour',
            'MIN' => 'Minute',
            'DAY' => 'Day',
            'WEE' => 'Week',
            'MON' => 'Month',
            'ANN' => 'Year',
        ],
        'Packaging' => [
            'XBX' => 'Box',
            'XCT' => 'Carton',
            'XPK' => 'Package',
            'XPA' => 'Packet',
            'XBG' => 'Bag',
            'XCS' => 'Case',
            'XCR' => 'Crate',
            'XPX' => 'Pallet',
            'XRO' => 'Roll',
            'XTN' => 'Tin',
        ],
        'Other' => [
            'KWH' => 'Kilowatt hour',
            'MTS' => 'Metre per second',
        ],
    ];

    /**
     * What this application used to send, and what it means.
     *
     * @var array<string, string>
     */
    public const LEGACY = [
        'PCE' => 'H87',
        'BX' => 'XBX',
        'PK' => 'XPK',
        'CT' => 'XCT',
    ];

    /**
     * Every code, flat.
     *
     * @return array<string, string> code => label
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * What to write, given what a caller sent.
     *
     * An unknown code is returned unchanged rather than replaced: validation
     * is the place that refuses one, and silently turning an unrecognised
     * unit into a piece would be a quantity error nobody could see.
     */
    public static function normalise(?string $code): string
    {
        if ($code === null || $code === '') {
            return self::DEFAULT;
        }

        return self::LEGACY[$code] ?? $code;
    }

    /** What to show a person. */
    public static function label(string $code): string
    {
        return self::all()[$code] ?? $code;
    }

    /**
     * The unit beside a quantity on a printed invoice, short and in both
     * languages - "kg / كغ" - so "2" means two kilograms in either. A code
     * not below prints as itself.
     *
     * Arabic first where more follows on the same line - "3 كغ / kg × 42.50
     * SAR" - because a number written straight after Arabic is laid out as
     * part of the Arabic, and the price would land before the unit.
     */
    public static function printed(?string $code, bool $arabicFirst = false): string
    {
        $code = self::normalise($code);

        if (! isset(self::PRINTED[$code])) {
            return $code;
        }

        return implode(' / ', $arabicFirst ? array_reverse(self::PRINTED[$code]) : self::PRINTED[$code]);
    }

    /**
     * English and Arabic abbreviations, as a receipt prints them.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PRINTED = [
        'H87' => ['pc', 'قطعة'],
        'EA' => ['ea', 'وحدة'],
        'C62' => ['unit', 'وحدة'],
        'SET' => ['set', 'طقم'],
        'KGM' => ['kg', 'كغ'],
        'GRM' => ['g', 'غ'],
        'MGM' => ['mg', 'ملغ'],
        'TNE' => ['t', 'طن'],
        'LBR' => ['lb', 'رطل'],
        'ONZ' => ['oz', 'أونصة'],
        'LTR' => ['L', 'لتر'],
        'MLT' => ['mL', 'مل'],
        'MTQ' => ['m³', 'م³'],
        'MTR' => ['m', 'م'],
        'CMT' => ['cm', 'سم'],
        'MMT' => ['mm', 'مم'],
        'KMT' => ['km', 'كم'],
        'YRD' => ['yd', 'ياردة'],
        'SMI' => ['mi', 'ميل'],
        'MTK' => ['m²', 'م²'],
        'HUR' => ['hr', 'ساعة'],
        'MIN' => ['min', 'دقيقة'],
        'DAY' => ['day', 'يوم'],
        'WEE' => ['wk', 'أسبوع'],
        'MON' => ['mo', 'شهر'],
        'ANN' => ['yr', 'سنة'],
        'XBX' => ['box', 'صندوق'],
        'XCT' => ['carton', 'كرتون'],
        'XPK' => ['pkg', 'عبوة'],
        'XPA' => ['pkt', 'رزمة'],
        'XBG' => ['bag', 'كيس'],
        'XCS' => ['case', 'علبة'],
        'XCR' => ['crate', 'قفص'],
        'XPX' => ['pallet', 'منصة'],
        'XRO' => ['roll', 'لفة'],
        'XTN' => ['tin', 'علبة معدنية'],
        'KWH' => ['kWh', 'كيلوواط ساعة'],
        'MTS' => ['m/s', 'م/ث'],
    ];

    /** Codes a request may send: the conformant ones, plus what we used to emit. */
    public static function accepted(): array
    {
        return [...self::codes(), ...array_keys(self::LEGACY)];
    }
}
