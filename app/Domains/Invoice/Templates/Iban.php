<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An International Bank Account Number, checked as ISO 13616-1 defines it:
 * two letters of country, two check digits, then up to thirty letters and
 * digits, 15 to 34 characters in all; the first four moved to the end, each
 * letter written as a number (A = 10 ... Z = 35), and the whole number taken
 * modulo 97 leaves 1 (ISO 7064 MOD 97-10).
 *
 * A typing slip - one wrong digit, two swapped - fails the check, so a
 * buyer is never shown an account no transfer can reach.
 */
final class Iban implements ValidationRule
{
    /** Capitals, without the spaces it is often written with. */
    public static function normalise(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    public static function valid(string $iban): bool
    {
        $iban = self::normalise($iban);

        if (preg_match('/\A[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\z/', $iban) !== 1) {
            return false;
        }

        $digits = '';

        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $character) {
            $digits .= ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
        }

        // Seven digits at a time after a two-digit remainder: every step
        // stays well within an integer.
        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valid($value)) {
            $fail(__('The IBAN is not valid: check its letters and digits.'));
        }
    }
}
