<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Services\PurchaseLedger;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Engine\Contracts\VatReturn as VatReturnPort;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Compliance\Engine\DTOs\VatReturnEntry;
use App\Domains\Compliance\Engine\Services\VatReturnTally;
use App\Domains\Invoice\Services\VatSummary;
use Carbon\CarbonImmutable;

/**
 * The ZATCA VAT return drafted for a period, in riyals, box by box as the
 * return form numbers them, from the disclosures KSA VAT Implementing
 * Regulations Art 59(2) lists:
 *
 *   1-5   sales: standard rated, to citizens (private healthcare and
 *         education), zero rated domestic, exports, exempt - VatSummary's
 *         boxes, credit notes for an earlier period under Adjustments
 *         (Art 59(2)(a), (g); Guideline for Tax Invoicing §7.1.1)
 *   6     total sales
 *   7     standard rated domestic purchases (Art 59(2)(b))
 *   8     imports with VAT paid at customs (Art 59(2)(f))
 *   9     imports of services and goods under the reverse charge
 *         (Art 59(2)(d); Art 47(1))
 *   10    zero rated purchases
 *   11    exempt purchases
 *   12    total purchases
 *   13    total VAT due for the period
 *   14    corrections from previous periods, up to SAR 5,000 (Art 63(3))
 *   15    VAT credit carried forward from previous periods
 *   16    net VAT due, or to claim
 *
 * Purchases come from Books (PurchaseLedger). In boxes 7-9 the VAT is the
 * input VAT the organization may deduct, and the share it may not deduct
 * is taken off the amount in Adjustments (Art 49-51) - as ZATCA's VAT
 * Guideline on Imports and Exports §6.3 Example 7 enters the 30% a bank
 * cannot deduct in box 9's adjustment. Adjustments are signed: what takes
 * away is below zero. A reverse charge's VAT is due as well as deductible
 * (Art 47(1)): it is added to VAT due in box 13, so a fully deductible one
 * costs nothing and a part-deductible one costs its share.
 */
class VatReturn implements VatReturnPort
{
    /** Purchases by treatment: the box each goes in. Out of scope goes in none. */
    private const PURCHASE_BOXES = [
        'standard' => '7',
        'import' => '8',
        'reverse_charge' => '9',
        'zero' => '10',
        'exempt' => '11',
    ];

    public function __construct(
        private readonly VatSummary $sales,
        private readonly PurchaseLedger $purchases,
    ) {}

    public function manualEntries(): array
    {
        return [
            'corrections' => 'Corrections from previous periods (between SAR -5,000 and 5,000)',
            'carried_forward' => 'VAT credit carried forward from previous period(s)',
        ];
    }

    public function draft(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, array $manual = []): VatReturnDraft
    {
        $tally = new VatReturnTally;
        $outOfScopeSales = 0.0;
        $outOfScopePurchases = 0.0;
        $capital = 0.0;
        $reverseCharged = 0.0;
        $missingRate = [];

        foreach ($this->sales->contributions($organizationId, $from, $to) as $row) {
            $invoice = $row['invoice'];

            if ($row['rate'] === null) {
                $missingRate[] = (string) $invoice->invoice_number;

                continue;
            }

            if ($row['box'] === null) {
                $outOfScopeSales += $row['base'];

                continue;
            }

            $tally->add((string) $row['box'], VatReturnEntry::SALE, (string) $invoice->id, (string) $invoice->invoice_number,
                $invoice->issue_date?->toDateString() ?? '', (string) $invoice->buyer_name,
                $row['adjusts'] ? 0.0 : $row['base'], $row['adjusts'] ? $row['base'] : 0.0, $row['vat']);
        }

        foreach ($this->purchases->rows($organizationId, $from, $to) as $row) {
            $purchase = $row['purchase'];
            $capital += $row['capital'];

            if ($row['treatment'] === PurchaseTreatment::OutOfScope) {
                $outOfScopePurchases += $row['base'];

                continue;
            }

            $box = self::PURCHASE_BOXES[$row['treatment']->value];
            $deductible = $row['treatment']->deductible();
            // The share it may not deduct, as an amount, off the amount.
            $withheld = $deductible ? -$row['base'] * (1 - (float) $purchase->deductible_share / 100) : 0.0;
            $reverseCharged += $row['treatment'] === PurchaseTreatment::ReverseCharge ? $row['vat'] : 0.0;

            $tally->add($box, VatReturnEntry::PURCHASE, (string) $purchase->id, (string) $purchase->supplier_invoice_number,
                $purchase->invoice_date->toDateString(), (string) $purchase->supplier?->name,
                $row['adjusts'] ? 0.0 : $row['base'], ($row['adjusts'] ? $row['base'] : 0.0) + $withheld,
                $deductible ? $row['deductible'] : 0.0);
        }

        $sales = [];

        foreach (VatSummary::BOXES as $number => $label) {
            $sales[] = $tally->box((string) $number, $label);
        }

        $sales[] = VatReturnTally::total('6', 'Total sales', VatReturnTally::sum($sales, 'amount'), VatReturnTally::sum($sales, 'adjustment'), VatReturnTally::sum($sales, 'vat'));

        $purchases = [
            $tally->box('7', 'Standard rated domestic purchases'),
            $tally->box('8', 'Imports subject to VAT paid at customs'),
            $tally->box('9', 'Imports subject to VAT accounted for through the reverse charge mechanism'),
            $tally->box('10', 'Zero rated purchases'),
            $tally->box('11', 'Exempt purchases'),
        ];
        $purchases[] = VatReturnTally::total('12', 'Total purchases', VatReturnTally::sum($purchases, 'amount'), VatReturnTally::sum($purchases, 'adjustment'), VatReturnTally::sum($purchases, 'vat'));

        $salesVat = (float) end($sales)->vat;
        $inputVat = (float) end($purchases)->vat;
        $due = VatReturnTally::cents($salesVat + $reverseCharged - $inputVat);
        $corrections = (float) ($manual['corrections'] ?? 0);
        $carried = (float) ($manual['carried_forward'] ?? 0);

        $totals = [
            VatReturnTally::total('9-due', 'VAT on reverse charge imports, due as well as deducted', null, null, $reverseCharged),
            VatReturnTally::total('13', 'Total VAT due for current period', null, null, $due),
            VatReturnTally::total('14', 'Corrections from previous periods (between SAR -5,000 and 5,000)', null, null, $corrections),
            VatReturnTally::total('15', 'VAT credit carried forward from previous period(s)', null, null, $carried),
            VatReturnTally::total('16', 'Net VAT due (or claimed)', null, null, $due + $corrections - $carried),
        ];

        $outside = [
            VatReturnTally::total('oos-sales', 'Out of scope sales, in no box', $outOfScopeSales, null, null),
            VatReturnTally::total('oos-purchases', 'Out of scope purchases, in no box', $outOfScopePurchases, null, null),
            VatReturnTally::total('capital', 'Of the purchases, capital assets', $capital, null, null),
        ];

        $problems = $missingRate === [] ? [] : [__('Left out, with no exchange rate to riyals: :numbers.', ['numbers' => implode(', ', $missingRate)])];

        if (abs($corrections) > 5000) {
            $problems[] = __('A correction over SAR 5,000 is not made in this return: file a correction of the earlier return instead (VAT Implementing Regulations Art 63).');
        }

        return new VatReturnDraft(
            title: 'VAT return',
            currency: FatooraConfig::CURRENCY_SAR,
            sections: [
                'VAT on sales' => $sales,
                'VAT on purchases' => $purchases,
                'Net VAT due' => $totals,
                'Not in the return' => $outside,
            ],
            notes: [
                __('A draft from your issued invoices and the purchases recorded in Books. Check it before you file it with ZATCA.'),
                __('Adjustments below zero take away: credit notes for an earlier period, and the share of input VAT you may not deduct.'),
            ],
            problems: $problems,
        );
    }
}
