<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Compliance\Engine\Contracts\SpecialSupplies as SpecialSuppliesPort;
use App\Domains\Compliance\Engine\DTOs\DeemedSupplyRules;
use App\Domains\Compliance\Engine\DTOs\DisbursementTreatment;
use App\Domains\Compliance\Engine\DTOs\VoucherRules;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * The UAE's special supplies.
 *
 * Summary tax invoice. Cabinet Decision No. 52 of 2017 (Executive
 * Regulation) Art 59(6): "A Registrant shall not issue separate Tax
 * Invoices in respect of supplies where he makes more than one supply of
 * Goods or Services to the same Person and those supplies are included on a
 * summary Tax Invoice issued and delivered to the Recipient", within 14 days
 * of the end of the calendar month in which the supplies took place (Art
 * 59(13)(b)); clause 6 still applies to electronic invoices (Art 59(16)).
 * On the e-invoice it is the summary flag of the transaction type (BTAE-02
 * position 4, 00010000), which needs the invoicing period (IBG-14; rule
 * IBR-138-AE) and is a tax invoice, never a commercial one (IBR-157-AE).
 * A negative total is a credit note instead (UAE Electronic Invoicing
 * Guidelines v1.1, scenario 4), which a summary of deliveries never is.
 *
 * A contract billed in stages. Decree-Law Art 26(1): for a contract with
 * periodic payments or consecutive invoices the date of supply is the
 * earliest of the tax invoice, the payment due date on it, the payment
 * received, and "the date of expiration of one year from the date the
 * Goods or Services were provided". The Electronic Invoicing Guidelines v1.1
 * (scenario 5, continuous supply; Appendix 5 §17.2, retention): the
 * milestone invoice is for the amount payable "after adjusting for the
 * retention amount", the calculation on "a separate commercial document"
 * and not on the e-invoice, and "a separate Electronic Invoice may
 * subsequently be issued for the retained amount at the point in time when
 * the Buyer becomes liable to release and settle the retained amount". Both
 * carry the continuous-supply flag (BTAE-02 position 5).
 *
 * A disbursement: not offered. The Decree-Law and Executive Regulation
 * extracts at hand hold no article on disbursements, and PINT AE's extracts
 * list no exemption reason for one; the out-of-scope category is for a
 * supply outside the UAE or "a specific exclusion from being regarded as a
 * supply" (Electronic Invoicing Guidelines v1.1), whose conditions for a
 * disbursement are not in the texts. Left to the owner's adviser.
 *
 * A voucher. Decree-Law Art 7(1): "The sale or issuance of any Voucher"
 * is not a supply "unless the Consideration received in respect thereof
 * exceeds its advertised monetary value"; Art 40 values that excess; Art 1
 * defines a voucher as an instrument giving the right to goods or services
 * "against the value stated thereon"; Executive Regulation Art 28(6): only
 * one whose redemption value "is identifiable at the time the Voucher is
 * issued". So the sale at face value makes no invoice, and the supply the
 * voucher pays for is taxed on its own. A voucher sold below its face value
 * changes that supply's value as a discount (Art 28(5)), which is not
 * offered. The extracts draw no line between single- and multi-purpose
 * vouchers.
 *
 * A deemed supply. Decree-Law Art 11: business assets given away, goods or
 * services with recoverable input tax used other than for the business (to
 * that extent), and what is owned on deregistration. Not deemed (Art 12): no
 * input tax recovered; samples or commercial gifts while their value "for
 * each Recipient ... within a 12-month period" stays within the amount the
 * Executive Regulation sets; and while the output tax on all deemed supplies
 * in 12 months is below its amount. Executive Regulation Art 5: AED 500 per
 * recipient, and AED 2,000 of output tax per supplier, "any amount exceeding
 * this threshold shall be considered Payable Tax", the 12 months being those
 * "preceding the end of the month" of the supply (Art 5(3)); the
 * Government Entity and Charity amount of Art 5(2)(b) is not offered. Value:
 * "the total cost incurred" (Art 37). A tax invoice is issued and kept where
 * there is no recipient (Art 65(2)): a tax invoice with the deemed-supply
 * flag (BTAE-02 01000000), the reserved buyer endpoint, and no due date
 * (IBR-127-AE). The VAT 201 box numbers are not in the extracts; the invoice
 * counts as a standard-rated supply of the seller's emirate.
 */
class SpecialSupplies implements SpecialSuppliesPort
{
    public function __construct(
        private readonly UaeSellerDetails $seller,
    ) {}

    public function summary(array $payload): array
    {
        return [...$payload, 'document_category' => 'tax', 'transaction_flags' => self::flagged($payload, PintAe::FLAG_SUMMARY)];
    }

    /** 14 days after the end of the calendar month (Executive Regulation Art 59(13)(b)). */
    public function summaryIssueBy(CarbonImmutable $periodEnd): CarbonImmutable
    {
        return $periodEnd->endOfMonth()->startOfDay()->addDays(14);
    }

    public function progress(array $payload): array
    {
        return [...$payload, 'document_category' => 'tax', 'transaction_flags' => self::flagged($payload, PintAe::FLAG_CONTINUOUS)];
    }

    public function disbursement(): ?DisbursementTreatment
    {
        return null;
    }

    public function vouchers(): VoucherRules
    {
        return new VoucherRules(saleIsSupply: false, source: 'UAE Federal Decree-Law No. 8 of 2017 Art 7(1)');
    }

    public function deemedSupplies(): DeemedSupplyRules
    {
        return new DeemedSupplyRules(
            recipientKinds: ['gift', 'sample'],
            recipientAllowance: '500.00',
            window: 'twelve_months',
            reliefCap: null,
            taxCap: '2000.00',
            source: 'UAE Federal Decree-Law No. 8 of 2017 Art 12; Cabinet Decision No. 52 of 2017 Art 5',
        );
    }

    /**
     * The recipient, or the seller itself where there is none, at the
     * seller's address and emirate (IBR-128-AE).
     */
    public function deemedSupply(array $payload, Organization $organization): array
    {
        return [
            ...$payload,
            'document_category' => 'tax',
            'transaction_flags' => self::flagged($payload, PintAe::FLAG_DEEMED_SUPPLY),
            'due_date' => null,
            'buyer_name' => $payload['buyer_name'] ?? $organization->name,
            'buyer_address' => array_filter([
                'street' => trim(implode(' ', array_filter([$organization->building_number, $organization->street]))) ?: null,
                'city' => $organization->city,
                'country_code' => 'AE',
            ]),
            'buyer_subdivision' => $this->seller->current($organization)['emirate'] ?? null,
        ];
    }

    /** One year after the supply (Decree-Law Art 26(1)(d)). */
    public function retentionDueBy(CarbonImmutable $invoiced): CarbonImmutable
    {
        return $invoiced->addYearNoOverflow()->startOfDay();
    }

    /**
     * The payload's transaction flags with one more set.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function flagged(array $payload, int $position): string
    {
        $flags = str_pad(substr((string) ($payload['transaction_flags'] ?? PintAe::NO_FLAGS), 0, 8), 8, '0');
        $flags[$position] = '1';

        return $flags;
    }
}
