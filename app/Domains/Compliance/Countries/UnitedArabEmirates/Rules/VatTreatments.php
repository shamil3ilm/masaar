<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Engine\Contracts\VatTreatments as VatTreatmentsPort;
use App\Domains\Compliance\Engine\DTOs\ExemptionReason;
use App\Domains\Compliance\Engine\DTOs\VatCategory;

/**
 * PINT AE's tax categories (Aligned-TaxCategoryCodes) and its exemption
 * reasons (Aligned-TaxExemptionCodes), with the code list's own names.
 *
 * Only an exempt line names a reason (IBR-167-AE); a zero-rated one names
 * none, so every reason here is in category E. The lists publish no Arabic,
 * so none is given.
 *
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-invoice/codelist/Aligned-TaxExemptionCodes/
 */
class VatTreatments implements VatTreatmentsPort
{
    /**
     * The "name" column of Aligned-TaxExemptionCodes (2022), by code. The
     * list misspells the first "Cetrain"; it is spelt correctly here, as a
     * buyer reads it.
     */
    private const NAMES = [
        'DL8.46.1' => 'Certain financial services',
        'DL8.46.2' => 'Supply of residential units (lease or sale)',
        'DL8.46.3' => 'Bare land',
        'DL8.46.4' => 'Local passenger transport',
    ];

    public function categories(): array
    {
        return [
            new VatCategory(PintTaxCategory::Standard->value, __('Standard rate (:rate%)', ['rate' => PintAe::STANDARD_RATE]), PintAe::STANDARD_RATE, taxed: true),
            new VatCategory(PintTaxCategory::ZeroRated->value, __('Zero-rated'), '0'),
            // ALIGNED-IBRP-E-05 and -O-05: no rate is stated.
            new VatCategory(PintTaxCategory::Exempt->value, __('Exempt'), null),
            new VatCategory(PintTaxCategory::OutOfScope->value, __('Out of scope'), null),
            // The rate is stated and the VAT is the buyer's, or not shown.
            new VatCategory(PintTaxCategory::ReverseCharge->value, __('Reverse charge'), PintAe::STANDARD_RATE),
            new VatCategory(PintTaxCategory::MarginScheme->value, __('Margin scheme'), PintAe::STANDARD_RATE),
        ];
    }

    public function exemptions(): array
    {
        return array_map(fn (string $code): ExemptionReason => $this->reason($code), PintAe::EXEMPTION_REASONS);
    }

    public function exemption(string $code): ?ExemptionReason
    {
        return in_array($code, PintAe::EXEMPTION_REASONS, true) ? $this->reason($code) : null;
    }

    private function reason(string $code): ExemptionReason
    {
        return new ExemptionReason($code, PintTaxCategory::Exempt->value, self::NAMES[$code] ?? null);
    }
}
