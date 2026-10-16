<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Enums;

/**
 * The tax categories a PINT AE document may carry (IBT-118, IBT-151).
 *
 * The UAE's own list, kept apart from the invoice's stored category: a line
 * stores a code, and only Peppol code decides what that code means on a UAE
 * document. N is "Standard rate additional VAT", which rule IBR-116-AE
 * requires on a margin-scheme invoice. The published list prints it as a
 * Greek capital Nu; the schematron compares against the Latin N.
 *
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-invoice/codelist/Aligned-TaxCategoryCodes/
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-invoice/rule/ibr-116-ae/
 */
enum PintTaxCategory: string
{
    case Standard = 'S';
    case ZeroRated = 'Z';
    case Exempt = 'E';
    case OutOfScope = 'O';
    // VAT levied from the buyer; the document charges none.
    case ReverseCharge = 'AE';
    // The margin scheme: the rate is stated, the VAT is not shown.
    case MarginScheme = 'N';

    /**
     * Whether the category states a rate. Exempt and out-of-scope must not
     * (ALIGNED-IBRP-E-05, ALIGNED-IBRP-O-05).
     */
    public function hasRate(): bool
    {
        return ! in_array($this, [self::Exempt, self::OutOfScope], true);
    }

    /**
     * Whether the document charges VAT on it. Reverse charge and the margin
     * scheme state a rate and charge nothing (IBR-108-AE, ALIGNED-IBRP-AE-09-AE).
     */
    public function chargesTax(): bool
    {
        return $this === self::Standard;
    }

    /**
     * What a line's stored category means on a UAE document; null for a code
     * the UAE does not have.
     */
    public static function fromStored(?string $code): ?self
    {
        return self::tryFrom(strtoupper(trim((string) $code)) ?: 'S');
    }
}
