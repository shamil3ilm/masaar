<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Services\PurchaseLedger;
use App\Domains\Books\Services\SalesBadDebts;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Engine\Contracts\VatReturn as VatReturnPort;
use App\Domains\Compliance\Engine\DTOs\BadDebtRules;
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
        'import_in_return' => '9',
        'zero' => '10',
        'exempt' => '11',
    ];

    /** Art 40(10): input tax on a purchase still unpaid this long after its supply is given back. */
    public const UNPAID_MONTHS = 12;

    public function __construct(
        private readonly VatSummary $sales,
        private readonly PurchaseLedger $purchases,
        private readonly SalesBadDebts $badDebts,
    ) {}

    /**
     * VAT Implementing Regulations Art 40(7): twelve months from the supply,
     * a certified accountant's certificate of the write-off, a customer who
     * is not a related person, and above SAR 100,000 owed evidence that
     * formal recovery failed.
     */
    /** None: the Kingdom's reverse charge is for supplies from abroad (Art 47(1)). */
    public function domesticReverseChargeGoods(string $organizationId): array
    {
        return [];
    }

    public function badDebtRules(): BadDebtRules
    {
        return new BadDebtRules(12, false, true, true, 100000.0, 'VAT Implementing Regulations Art 40(7)');
    }

    /** No: input tax is deducted on holding the invoice (Art 49(7)), paid or not. */
    public function inputTaxOnPayment(): bool
    {
        return false;
    }

    /** Twelve: Art 40(10) gives back what is unpaid then, Art 40(11) reclaims it on payment. */
    public function unpaidInputTaxMonths(): ?int
    {
        return self::UNPAID_MONTHS;
    }

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

        // Bad-debt relief takes the output tax on the unpaid share back off
        // box 1 in Adjustments; a later payment gives it back (Art 40(7), (9)).
        foreach ($this->badDebts->rows($organizationId, $from, $to) as $relief) {
            $invoice = $relief['invoice'];
            $rate = $this->sales->rate($invoice) ?? 0.0;

            foreach ($this->sales->categories($invoice) as $category) {
                if ($category['code'] !== 'S') {
                    continue;
                }

                $tally->add('1', VatReturnEntry::SALE, (string) $invoice->id, (string) $invoice->invoice_number, $invoice->issue_date?->toDateString() ?? '',
                    (string) $invoice->buyer_name, 0.0, -$relief['share'] * $category['base'] * $rate, -$relief['share'] * $category['tax'] * $rate);
            }
        }

        foreach ($this->purchases->rows($organizationId, $from, $to) as $row) {
            $purchase = $row['purchase'];
            $capital += $row['capital'];

            if ($row['treatment'] === PurchaseTreatment::OutOfScope) {
                $outOfScopePurchases += $row['base'];

                continue;
            }

            $box = self::PURCHASE_BOXES[$row['treatment']->value] ?? null;

            if ($box === null) {
                continue;
            }

            $deductible = $row['treatment']->deductible();
            // The share it may not deduct, as an amount, off the amount.
            $withheld = $deductible ? -$row['base'] * (1 - (float) $purchase->deductible_share / 100) : 0.0;
            $reverseCharged += $row['treatment']->selfAssessed() ? $row['vat'] : 0.0;

            $tally->add($box, VatReturnEntry::PURCHASE, (string) $purchase->id, (string) $purchase->supplier_invoice_number,
                $purchase->invoice_date->toDateString(), (string) $purchase->supplier?->name,
                $row['adjusts'] ? 0.0 : $row['base'], ($row['adjusts'] ? $row['base'] : 0.0) + $withheld,
                $deductible ? $row['deductible'] : 0.0);
        }

        // Unpaid twelve months after the supply: the input VAT on what is
        // unpaid comes off box 7 in Adjustments, and a later payment gives
        // it back (Art 40(10), (11)).
        foreach ($this->purchases->unpaidAdjustments($organizationId, $from, $to, self::UNPAID_MONTHS) as $row) {
            $purchase = $row['purchase'];
            $tally->add('7', VatReturnEntry::PURCHASE, (string) $purchase->id, (string) $purchase->supplier_invoice_number,
                $purchase->invoice_date->toDateString(), (string) $purchase->supplier?->name, 0.0, $row['base'], $row['deductible']);
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

        $links = [];
        $unpaid = $this->purchases->unpaidOver($organizationId, $to, self::UNPAID_MONTHS);

        if ($unpaid !== []) {
            $problems[] = __('Unpaid 12 months after the supply: :numbers. The input VAT on what is unpaid is given back in box 7, and reclaimed in the period you pay (VAT Implementing Regulations Art 40(10), (11)).', [
                'numbers' => implode(', ', array_map(fn (array $row): string => (string) $row['purchase']->supplier_invoice_number, $unpaid)),
            ]);
            $links[__('Purchases unpaid over 12 months')] = route('portal.books.purchases.unpaid', ['as_at' => $to->toDateString()]);
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
            links: $links,
        );
    }
}
