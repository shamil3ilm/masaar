<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * How an instalment plan - one agreed total in N parts - is invoiced, by
 * when the supply happens against when it is paid. Tax is due "on the date
 * of the supply of Goods or Services, the date of issuance of the tax
 * invoice or upon partial or full receipt of the Consideration, whichever
 * comes first" (GCC Unified VAT Agreement, Article 23(1)); the date of
 * supply is when the goods are placed at the customer's disposal or the
 * service is completed (23(2)(a) to (d)).
 *
 * Delivered: the goods or the service are delivered up front and paid in
 * instalments. Nothing makes the instalments separate supplies, so the
 * whole value is supplied on delivery (23(1), 23(2)) and invoiced then -
 * no later than the fifteenth of the next month (Implementing Regulations
 * Article 53(5)(b)). One tax invoice for the full value, made on the plan's
 * first day; the instalments are tracked as payments due against it.
 *
 * Periodic: the supply itself runs over the plan's periods - a service
 * performed month by month - and the contract makes the consideration
 * payable periodically. Each instalment is then a separate supply, due on
 * the instalment's due date, its payment or its invoice, whichever is
 * earliest (Agreement 23(3); Implementing Regulations Article 20(1) and
 * 20(2); ZATCA's Guideline for Tax Invoicing and Records, 4.5.2, example
 * 9-A). Each instalment is a tax invoice for its share and its period.
 *
 * Prepayment: the buyer pays ahead of the supply - school fees for a year
 * not yet taught. Receiving an advance makes tax due to the extent paid
 * (23(1)) and "necessitates issuance of a tax invoice", type 386
 * (E-Invoicing Detailed Guidelines 8.1 and 8.1.a, p40), so each instalment
 * but the last is a prepayment invoice for its share. The last is the final
 * invoice (388) for the whole supply, settling every advance by its number,
 * issue date and time (KSA-26 to KSA-30) and what each prepaid per VAT
 * category (KSA-31 to KSA-34), with the prepaid amount (BT-113) the sum of
 * them with their VAT (8.1.b and 8.1.c, p40-41) - many advances to one final
 * invoice, as 8.1.h (p43) allows. The buyer pays the last share.
 *
 * Where it is unclear whether a supply was delivered or runs over the
 * periods, Delivered is the reading that never declares tax later than the
 * law asks.
 */
enum InstalmentModel: string
{
    case Delivered = 'delivered';
    case Periodic = 'periodic';
    case Prepayment = 'prepayment';

    /** Whether each instalment is a share of the invoice's lines, on an invoice of its own. */
    public function splitsLines(): bool
    {
        return $this !== self::Delivered;
    }
}
