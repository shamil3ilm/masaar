<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\SpecialSupplies as SpecialSuppliesPort;
use App\Domains\Invoice\Enums\InvoiceType;
use Carbon\CarbonImmutable;

/**
 * Saudi Arabia's special supplies.
 *
 * Summary tax invoice. KSA VAT Implementing Regulations Art 53(4): "a
 * Taxable Person may issue a summary Tax Invoice that includes more than
 * one separate supply of Goods or Services to the same Customer in a period
 * not exceeding one calendar month ... and must be issued no later than the
 * fifteenth day of the month following the month for which the Tax Invoice
 * pertains" (GCC VAT Agreement Art 55(3); ZATCA Guideline for Tax Invoicing
 * §4.4: same terms as a basic tax invoice, no other format, and no separate
 * invoices for the supplies it covers). A summary simplified invoice is for
 * the suppliers Art 53(7)(c)-(d) names - banks, financial institutions,
 * insurers and those the Governor approves - so the summary here is a tax
 * (standard) invoice. On the e-invoice it is KSA-2 position 6 (XML
 * Implementation Standard; BR-KSA-06), with the period as the supply date
 * (KSA-5) and supply end date (KSA-24), which BR-KSA-35 and BR-KSA-36
 * check, and which BR-KSA-72 requires of a simplified summary.
 */
class SpecialSupplies implements SpecialSuppliesPort
{
    public function summary(array $payload): array
    {
        return [...$payload, 'type' => InvoiceType::Standard->value, 'is_summary' => true];
    }

    /** The 15th of the month after the period (IR Art 53(4)). */
    public function summaryIssueBy(CarbonImmutable $periodEnd): CarbonImmutable
    {
        return $periodEnd->startOfMonth()->addMonthNoOverflow()->day(15)->startOfDay();
    }
}
