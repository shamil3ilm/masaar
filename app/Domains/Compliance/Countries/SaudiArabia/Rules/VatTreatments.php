<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\VatTreatments as VatTreatmentsPort;
use App\Domains\Compliance\Engine\DTOs\ExemptionReason;
use App\Domains\Compliance\Engine\DTOs\VatCategory;

/**
 * The tax categories of BR-KSA-18 (S, Z, E, O) and the exemption and
 * exception reasons of §11.2.4 of ZATCA's XML Implementation Standard, with
 * the authority's own English and Arabic texts (FatooraConfig).
 *
 * Private healthcare and private education are zero-rated only when supplied
 * to a citizen, so those two reasons name the buyer by national ID
 * (BR-KSA-49). OOS, DIPLOMAT and DUTYFREE have no official text, so the
 * seller writes the reason.
 */
class VatTreatments implements VatTreatmentsPort
{
    public function categories(): array
    {
        return [
            new VatCategory(FatooraConfig::TAX_CATEGORY_STANDARD, __('Standard rate (:rate%)', ['rate' => InvoiceRules::STANDARD_RATE]), InvoiceRules::STANDARD_RATE, taxed: true),
            new VatCategory(FatooraConfig::TAX_CATEGORY_ZERO_RATED, __('Zero-rated'), '0'),
            new VatCategory(FatooraConfig::TAX_CATEGORY_EXEMPT, __('Exempt'), '0'),
            new VatCategory(FatooraConfig::TAX_CATEGORY_OUT_OF_SCOPE, __('Out of scope'), '0'),
        ];
    }

    public function exemptions(): array
    {
        return array_map(fn (string $code): ExemptionReason => $this->reason($code), FatooraConfig::exemptionCodes());
    }

    public function exemption(string $code): ?ExemptionReason
    {
        return isset(FatooraConfig::CODE_CATEGORIES[$code]) ? $this->reason($code) : null;
    }

    private function reason(string $code): ExemptionReason
    {
        $english = FatooraConfig::EXEMPTION_REASONS_EN[$code] ?? null;

        return new ExemptionReason(
            code: $code,
            category: FatooraConfig::CODE_CATEGORIES[$code],
            english: $english,
            arabic: FatooraConfig::EXEMPTION_REASONS_AR[$code] ?? null,
            ownText: $english === null,
            buyerScheme: in_array($code, FatooraConfig::NAMED_BUYER_EXEMPTIONS, true) ? FatooraConfig::CITIZEN_BUYER_SCHEME : null,
        );
    }
}
