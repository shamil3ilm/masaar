<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * How an instalment plan - one agreed total in N parts - is invoiced.
 *
 * Invoice: the supply is delivered, or delivered as it is paid, so each
 * instalment is a tax invoice for its share. The prepayment fields are not
 * used: ZATCA has them filled "only if the taxpayer has issued a separate
 * invoice at the time of receiving payment in advance and not otherwise"
 * (E-Invoicing Detailed Guidelines 8.1.c, p41).
 *
 * Prepayment: the buyer pays ahead of the supply - school fees for a year
 * not yet taught. Receiving an advance "necessitates issuance of a tax
 * invoice", type 386 (8.1 and 8.1.a, p40), so each instalment but the last
 * is a prepayment invoice for its share. The last is the final invoice
 * (388) for the whole supply, settling every advance by its number, issue
 * date and time (KSA-26 to KSA-30) and what each prepaid per VAT category
 * (KSA-31 to KSA-34), with the prepaid amount (BT-113) the sum of them
 * with their VAT (8.1.b and 8.1.c, p40-41) - many advances to one final
 * invoice, as 8.1.h (p43) allows. The buyer pays the last share.
 */
enum InstalmentModel: string
{
    case Invoice = 'invoice';
    case Prepayment = 'prepayment';
}
