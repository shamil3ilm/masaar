<?php

declare(strict_types=1);

namespace App\Domains\Billing\Settings;

/**
 * An International Bank Account Number, checked as ISO 13616-1 defines it:
 * two letters for the country, two check digits, then the account, and the
 * whole passing the ISO 7064 MOD 97-10 check.
 *
 * The length is the one the SWIFT IBAN Registry gives the country, where
 * this table knows it: a typo that drops a digit fails here rather than at
 * the customer's bank. A country it does not list is held to the standard's
 * limits, 15 to 34 characters.
 */
final class Iban
{
    /** IBAN length by country, from the SWIFT IBAN Registry. */
    private const LENGTHS = [
        'AE' => 23, 'BH' => 22, 'DE' => 22, 'EG' => 29, 'FR' => 27, 'GB' => 22,
        'JO' => 30, 'KW' => 30, 'NL' => 18, 'OM' => 23, 'QA' => 29, 'SA' => 24, 'TR' => 26,
    ];

    /** Spaces and lower case as people type it, gone. */
    public static function normalise(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    public static function valid(string $iban): bool
    {
        $iban = self::normalise($iban);

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $length = self::LENGTHS[substr($iban, 0, 2)] ?? null;

        if ($length !== null && strlen($iban) !== $length) {
            return false;
        }

        // Country and check digits moved to the end, letters as numbers
        // (A = 10 ... Z = 35), and the remainder by 97 must be 1. Taken a
        // few digits at a time, so no number outgrows an integer.
        $digits = '';

        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $character) {
            $digits .= ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /** As it is printed: groups of four. */
    public static function format(string $iban): string
    {
        return trim(chunk_split(self::normalise($iban), 4, ' '));
    }
}
