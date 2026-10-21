<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Organization\Models\Organization;

/**
 * The seller identification (BT-29) and its scheme (BT-29-1).
 *
 * BR-KSA-08 (XML Implementation Standard v1.2, p48-49; the SDK's
 * schematron, allowed-seller): exactly one of
 *   CRN  Commercial Registration number
 *   MOM  MOMRAH licence
 *   MLS  MHRSD licence
 *   700  700 number
 *   SAG  MISA licence
 *   OTH  another ID
 * containing only alphanumeric characters; where several exist, the first
 * in that order. BR-KSA-F-13 warns on a numeric value with a run of eight
 * sequential or repeated digits. The VAT number is not a scheme here.
 *
 * The number is kept in the organization's cr_number column and the
 * scheme in its compliance_profile, CRN when none was chosen.
 */
final class SellerIdentification
{
    public const PROFILE_KEY = 'seller_id_scheme';

    public const DEFAULT = 'CRN';

    /** In BR-KSA-08's order of precedence. */
    public const SCHEMES = ['CRN', 'MOM', 'MLS', '700', 'SAG', 'OTH'];

    public static function scheme(Organization $organization): string
    {
        $scheme = $organization->compliance_profile[self::PROFILE_KEY] ?? null;

        return in_array($scheme, self::SCHEMES, true) ? $scheme : self::DEFAULT;
    }

    /** Whether the organization holds a seller identification ZATCA accepts. */
    public static function valid(Organization $organization): bool
    {
        return self::problem(self::scheme($organization), (string) $organization->cr_number) === null;
    }

    /**
     * Why a value is not a seller identification under a scheme, or null.
     */
    public static function problem(string $scheme, string $value): ?string
    {
        if (! in_array($scheme, self::SCHEMES, true)) {
            return __('Choose one of the seller identification types ZATCA accepts.');
        }

        $format = match ($scheme) {
            'CRN' => preg_match('/^\d{10}$/', $value) === 1,
            '700' => preg_match('/^7\d{9}$/', $value) === 1,
            default => preg_match('/^[A-Za-z0-9]{1,50}$/', $value) === 1,
        };

        if (! $format) {
            return match ($scheme) {
                'CRN' => __('The commercial registration number is 10 digits.'),
                '700' => __('The 700 number is 10 digits starting with 7.'),
                default => __('The seller identification may contain only letters and digits.'),
            };
        }

        // BR-KSA-F-13: a numeric ID with eight sequential or repeated digits.
        if (preg_match('/^\d+$/', $value) === 1
            && preg_match('/01234567|12345678|23456789|76543210|87654321|98765432|(\d)\1{7}/', $value) === 1) {
            return __('This seller identification looks like a placeholder; enter the real number.');
        }

        return null;
    }

    /**
     * Each scheme as the company page offers it, in the reader's language.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            'CRN' => __('Commercial registration (CR) number'),
            'MOM' => __('MOMRAH licence'),
            'MLS' => __('MHRSD licence'),
            '700' => __('700 number'),
            'SAG' => __('MISA licence'),
            'OTH' => __('Other ID'),
        ];
    }

    /**
     * The scheme's name, in English and Arabic, as a printed label.
     *
     * @return array{0: string, 1: string}
     */
    public static function label(string $scheme): array
    {
        return match ($scheme) {
            'MOM' => ['MOMRAH licence', 'رخصة وزارة الشؤون البلدية والقروية والإسكان'],
            'MLS' => ['MHRSD licence', 'رخصة وزارة الموارد البشرية والتنمية الاجتماعية'],
            '700' => ['700 number', 'الرقم الموحد 700'],
            'SAG' => ['MISA licence', 'رخصة وزارة الاستثمار'],
            'OTH' => ['Other ID', 'معرّف آخر'],
            default => ['CR', 'السجل التجاري'],
        };
    }
}
