<?php

declare(strict_types=1);

namespace App\Domains\Organization\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A VAT registration number, checked where it is typed.
 *
 * Four places accepted 'max:50' of any characters. Two of them write to a
 * column that holds fifteen - organizations.vat_number and
 * licenses.organization_vat - and MySQL runs in strict mode, so a sixteenth
 * character was a 500 with a SQL message rather than a 422 naming the field.
 *
 * The other two reach the authority. A buyer VAT number that is not fifteen
 * digits fails ZATCA's BR-KSA-44 at submission - which happens after the
 * invoice has been issued, numbered, hashed and written into the chain. The
 * remedy for a rejected invoice is a credit note, so a typo in a VAT number
 * cost a credit note instead of a form error.
 *
 * Fifteen digits and no more than that. ZATCA's BR-KSA-40 and BR-KSA-44 also
 * require the first and last digits to be "3", and that is deliberately not
 * checked here: this platform files for the UAE as well, whose TRN is also
 * fifteen digits and has no such prefix. The Saudi-specific part is enforced
 * where the jurisdiction is known - CsrData when the CSR is built, and the
 * authority's own schematron at submission.
 */
final class VatNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('The :attribute must be 15 digits.');

            return;
        }

        if (preg_match('/^\d{15}$/', (string) $value) !== 1) {
            $fail('The :attribute must be exactly 15 digits.');
        }
    }
}
