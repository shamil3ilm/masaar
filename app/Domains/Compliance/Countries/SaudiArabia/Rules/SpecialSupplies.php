<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\SpecialSupplies as SpecialSuppliesPort;
use App\Domains\Compliance\Engine\DTOs\DisbursementTreatment;
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
 *
 * A contract billed in stages. The extracts of the Implementing Regulations
 * say nothing of construction contracts or retention as such; what applies
 * is the continuous-supply rule, IR Art 20(2): each occurrence "is deemed a
 * separate Supply on which the tax becomes due on the earlier of the date an
 * Invoice is issued or payment is made ... to the extent of the amount
 * invoiced or paid" (GCC VAT Agreement Art 23(1)). A stage invoiced net of
 * retention is taxed on what it invoices; the retention is taxed when it is
 * invoiced or paid, and at the latest twelve months on (Art 20(3)). The
 * e-invoice states nothing more: KSA-2 position 8 (continuous supply) is in
 * the newer BR-KSA-06 but required by no rule, so it is not written and
 * every document keeps the seven characters its signature already covers.
 *
 * A disbursement. The extracts of the Implementing Regulations hold no
 * article on disbursements. The GCC VAT Agreement Art 26(6)(c) takes out of
 * the value of a supply "amounts paid by the Taxable Supplier in the name of
 * and to the account of the Customer. In this case, the Taxable Supplier may
 * not deduct Tax paid on these expenses" - against Art 26(3), which keeps in
 * it "all the expenses imposed by the Taxable Supplier on the Customer". So
 * a line is a disbursement only on those conditions, which the seller
 * confirms, and is outside the scope of VAT: category O with VATEX-SA-OOS,
 * whose reason ZATCA leaves to the taxpayer ("Reason is free text, to be
 * provided by the taxpayer on case to case basis", XML Implementation
 * Standard; BR-KSA-24 requires one).
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

    public function progress(array $payload): array
    {
        return $payload;
    }

    public function disbursement(): ?DisbursementTreatment
    {
        return new DisbursementTreatment(
            category: FatooraConfig::TAX_CATEGORY_OUT_OF_SCOPE,
            exemptCode: 'VATEX-SA-OOS',
            reason: "Disbursement paid in the customer's name and for its account / مبلغ مدفوع باسم العميل ولحسابه",
            conditions: [
                "The cost was paid in the customer's name.",
                "It was paid for the customer's account, and the customer is charged exactly what was paid.",
                'You do not deduct the input VAT on it.',
            ],
            source: 'GCC VAT Agreement Art 26(6)(c)',
        );
    }

    /** Twelve months after the supply (IR Art 20(3)). */
    public function retentionDueBy(CarbonImmutable $invoiced): CarbonImmutable
    {
        return $invoiced->addMonthsNoOverflow(12)->startOfDay();
    }
}
