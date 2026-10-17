<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Contracts\JurisdictionProfile;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintDocumentCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;

/**
 * PINT AE: the UAE's billing and self-billing specifications.
 *
 * Every value here is read off the published specification and its code
 * lists (PINT AE Billing 1.0.4, PDK 1.4.4), not remembered:
 *
 * @see https://docs.peppol.eu/poac/ae/pint-ae/bis/
 * @see https://docs.peppol.eu/poac/ae/pint-ae-sb/bis/
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-invoice/codelist/UNCL1001-inv/
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-creditnote/codelist/UNCL1001-cn/
 */
final class PintAe implements JurisdictionProfile
{
    public const BILLING = 'urn:peppol:pint:billing-1@ae-1';

    public const SELF_BILLING = 'urn:peppol:pint:selfbilling-1@ae-1';

    /** The participant scheme: 0235 plus the 10-digit TIN. */
    public const SCHEME = '0235';

    /**
     * Buyer endpoints the guidelines reserve, for a buyer with no Peppol
     * identifier of its own (Guidelines v1.1, sections 10.2.2 and 10.4).
     */
    public const BUYER_NOT_YET_ON_EINVOICING = '9900000098';

    public const DEEMED_SUPPLY_BUYER = '9900000097';

    public const EXPORT_BUYER = '9900000099';

    /**
     * Positions in the eight-character transaction-type code (BTAE-02,
     * cbc:ProfileExecutionID), left to right, '1' where it applies.
     */
    public const FLAG_FREE_ZONE = 0;

    public const FLAG_DEEMED_SUPPLY = 1;

    public const FLAG_MARGIN_SCHEME = 2;

    public const FLAG_SUMMARY = 3;

    public const FLAG_CONTINUOUS = 4;

    public const FLAG_DISCLOSED_AGENT = 5;

    public const FLAG_ECOMMERCE = 6;

    public const FLAG_EXPORT = 7;

    /** No scenario applies. */
    public const NO_FLAGS = '00000000';

    /** IBR-190-AE: a standard-rated line is at 5.00, and nothing else. */
    public const STANDARD_RATE = '5';

    /** The seven emirates (IBR-128-AE). */
    public const EMIRATES = ['AUH', 'DXB', 'SHJ', 'UAQ', 'FUJ', 'AJM', 'RAK'];

    /** Legal registration types (BTAE-15, BTAE-16): trade licence, Emirates ID, passport, Cabinet decision. */
    public const LEGAL_REGISTRATION_TYPES = ['TL', 'EID', 'PAS', 'CD'];

    /** Credit note reasons (BTAE-03), from the CreditReason code list. VD is a volume discount. */
    public const CREDIT_REASONS = ['DL8.61.1.A', 'DL8.61.1.B', 'DL8.61.1.C', 'DL8.61.1.D', 'DL8.61.1.E', 'VD'];

    /** Exemption reasons (IBT-186), from the Aligned-TaxExemptionCodes list. */
    public const EXEMPTION_REASONS = ['DL8.46.1', 'DL8.46.2', 'DL8.46.3', 'DL8.46.4'];

    /**
     * IBT-105 and IBT-145, the reason codes a charge may carry: UNCL 7161, as
     * IBR-CL-20 of the PINT schematron lists it.
     *
     * @var list<string>
     */
    public const CHARGE_REASON_CODES = [
        'AA', 'AAA', 'AAC', 'AAD', 'AAE', 'AAF', 'AAH', 'AAI', 'AAS', 'AAT', 'AAV', 'AAY',
        'AAZ', 'ABA', 'ABB', 'ABC', 'ABD', 'ABF', 'ABK', 'ABL', 'ABN', 'ABR', 'ABS', 'ABT',
        'ABU', 'ACF', 'ACG', 'ACH', 'ACI', 'ACJ', 'ACK', 'ACL', 'ACM', 'ACS', 'ADC', 'ADE',
        'ADJ', 'ADK', 'ADL', 'ADM', 'ADN', 'ADO', 'ADP', 'ADQ', 'ADR', 'ADT', 'ADW', 'ADY',
        'ADZ', 'AEA', 'AEB', 'AEC', 'AED', 'AEF', 'AEH', 'AEI', 'AEJ', 'AEK', 'AEL', 'AEM',
        'AEN', 'AEO', 'AEP', 'AES', 'AET', 'AEU', 'AEV', 'AEW', 'AEX', 'AEY', 'AEZ', 'AJ',
        'AU', 'CA', 'CAB', 'CAD', 'CAE', 'CAF', 'CAI', 'CAJ', 'CAK', 'CAL', 'CAM', 'CAN',
        'CAO', 'CAP', 'CAQ', 'CAR', 'CAS', 'CAT', 'CAU', 'CAV', 'CAW', 'CAX', 'CAY', 'CAZ',
        'CD', 'CG', 'CS', 'CT', 'DAB', 'DAD', 'DAC', 'DAF', 'DAG', 'DAH', 'DAI', 'DAJ',
        'DAK', 'DAL', 'DAM', 'DAN', 'DAO', 'DAP', 'DAQ', 'DL', 'EG', 'EP', 'ER', 'FAA',
        'FAB', 'FAC', 'FC', 'FH', 'FI', 'GAA', 'HAA', 'HD', 'HH', 'IAA', 'IAB', 'ID',
        'IF', 'IR', 'IS', 'KO', 'L1', 'LA', 'LAA', 'LAB', 'LF', 'MAE', 'MI', 'ML',
        'NAA', 'OA', 'PA', 'PAA', 'PC', 'PL', 'PRV', 'RAB', 'RAC', 'RAD', 'RAF', 'RE',
        'RF', 'RH', 'RV', 'SA', 'SAA', 'SAD', 'SAE', 'SAI', 'SG', 'SH', 'SM', 'SU',
        'TAB', 'TAC', 'TT', 'TV', 'V1', 'V2', 'WH', 'XAA', 'YY', 'ZZZ',
    ];

    /** Goods under the domestic reverse charge (BTAE-09), from the GoodsType list. */
    public const REVERSE_CHARGE_GOODS = ['DL8.48.8.2', 'DL8.48.8.1', 'DL8.48.3.1', 'DL8.48.3.2', 'DL8.48.3.3'];

    public function country(): string
    {
        return 'AE';
    }

    public function customizationId(PintDocumentCategory $category): string
    {
        return $category->isSelfBilled() ? self::SELF_BILLING : self::BILLING;
    }

    public function profileId(PintDocumentCategory $category): string
    {
        return $category->isSelfBilled() ? 'urn:peppol:bis:selfbilling' : 'urn:peppol:bis:billing';
    }

    /**
     * 480 and 81 are the "out of scope of tax" codes, which is how the UAE
     * names a commercial invoice and credit note.
     */
    public function typeCode(PintDocumentCategory $category): string
    {
        return match ($category) {
            PintDocumentCategory::TaxInvoice => '380',
            PintDocumentCategory::TaxCreditNote => '381',
            PintDocumentCategory::CommercialInvoice => '480',
            PintDocumentCategory::CommercialCreditNote => '81',
            PintDocumentCategory::SelfBilledInvoice => '389',
            PintDocumentCategory::SelfBilledCreditNote => '261',
        };
    }

    public function endpointScheme(): string
    {
        return self::SCHEME;
    }

    /**
     * A commercial document may carry only exempt, out-of-scope and
     * zero-rated lines (IBR-122-AE).
     */
    public function allowedTaxCategories(PintDocumentCategory $category): array
    {
        if ($category->isCommercial()) {
            return [PintTaxCategory::Exempt, PintTaxCategory::OutOfScope, PintTaxCategory::ZeroRated];
        }

        return PintTaxCategory::cases();
    }

    public function taxCurrency(): string
    {
        return 'AED';
    }

    /** IBR-190-AE: see STANDARD_RATE. */
    public function standardRate(): string
    {
        return self::STANDARD_RATE;
    }
}
