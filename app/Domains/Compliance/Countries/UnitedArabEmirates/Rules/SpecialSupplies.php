<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Engine\Contracts\SpecialSupplies as SpecialSuppliesPort;
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
 */
class SpecialSupplies implements SpecialSuppliesPort
{
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
