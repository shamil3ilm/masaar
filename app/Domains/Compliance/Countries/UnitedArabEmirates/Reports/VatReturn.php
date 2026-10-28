<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Services\PurchaseLedger;
use App\Domains\Books\Services\SalesBadDebts;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Compliance\Engine\Contracts\VatReturn as VatReturnPort;
use App\Domains\Compliance\Engine\DTOs\BadDebtRules;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Compliance\Engine\DTOs\VatReturnEntry;
use App\Domains\Compliance\Engine\Services\VatReturnTally;
use App\Domains\Invoice\Services\VatSummary;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * The FTA VAT return (VAT 201) drafted for a period, in dirhams, box by box,
 * from the details Cabinet Decision No. 52 of 2017 Art 64(5) says a return
 * holds:
 *
 *   1a-1g  standard rated supplies in each emirate (Art 64(5)(d)), the
 *          emirate being that of the establishment the supply is made from
 *          (Art 72(2), SupplyEmirate)
 *   2      tax refunds to tourists - typed in, the books do not hold it
 *   3      supplies subject to the reverse charge: services and goods from
 *          abroad the organization accounts for itself (Federal Decree-Law
 *          No. 8 of 2017 Art 48(1); Art 64(5)(g))
 *   4      zero rated supplies (Art 64(5)(e))
 *   5      exempt supplies (Art 64(5)(f))
 *   6      goods imported into the UAE whose VAT the registrant accounts
 *          for in the return (Cabinet Decision No. 52 of 2017 Art 48(1),
 *          (4)(b)); recovered in box 10
 *   7      adjustments to goods imported
 *
 *   An import whose VAT was paid at customs before release (Art 50(1)) is
 *   not declared again in box 6: nothing more is due on it, and the VAT
 *   paid is input tax (Federal Decree-Law No. 8 of 2017 Art 54), in box 9
 *   with the other standard rated expenses.
 *   8      totals
 *   9      standard rated expenses, with the recoverable VAT (Art 64(5)(h);
 *          Decree-Law Art 54-55)
 *   10     supplies subject to the reverse charge: like box 9, the amount
 *          of the expenses (those of boxes 3 and 6) and the VAT
 *          recoverable on them - so box 11's amount and VAT cover the same
 *          expenses (VAT 201: "Supplies subject to the reverse charge
 *          provisions", Amount and Recoverable VAT)
 *   11     totals
 *   12-14  due tax, recoverable tax and payable tax (Art 64(5)(i), (j))
 *
 * Sales come from the issued documents (UaeVatSummary::contributions),
 * purchases from Books (PurchaseLedger). A sale the buyer accounts for under
 * the reverse charge, a margin-scheme sale and an out-of-scope one are shown
 * apart, in no box: the e-invoice states no VAT on them.
 */
class VatReturn implements VatReturnPort
{
    public function __construct(
        private readonly UaeVatSummary $sales,
        private readonly PurchaseLedger $purchases,
        private readonly SupplyEmirate $emirates,
        private readonly SalesBadDebts $badDebts,
        private readonly VatSummary $vat,
        private readonly UaeSellerDetails $seller,
    ) {}

    /**
     * Goods under the domestic reverse charge (Decree-Law Art 48), by the
     * codes the e-invoice's goods type takes (PintAe::REVERSE_CHARGE_GOODS):
     * Clause 3, oil, gas and hydrocarbons between Registrants (on the
     * buyer's written declaration, Clause 4); Clause 8, goods the Cabinet
     * specifies. Which code within each clause is which goods is in the
     * Cabinet's decisions and the code list's descriptions, not in the texts
     * read here, so each is named by its clause and code. Only for a buyer
     * with a TRN.
     *
     * The categories stay neutral on purpose. What is still to be verified
     * against the Cabinet decisions themselves, which the local texts do not
     * include, before any code is given a name: which goods each of
     * DL8.48.8.1 and DL8.48.8.2 covers (the decisions under Art 48(8) that
     * specify them, and the date each took effect); whether the three Art
     * 48(3) codes divide crude oil, refined oil and natural gas or another
     * way; and whether a decision adds or removes goods after the GoodsType
     * code list this reads. Until then the buyer chooses the code its
     * supplier's e-invoice states (BTAE-09), and the return places it by
     * clause.
     */
    public function domesticReverseChargeGoods(string $organizationId): array
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization === null || blank($this->seller->current($organization)['trn'] ?? null)) {
            return [];
        }

        return [
            'DL8.48.3.1' => 'Oil, gas or hydrocarbons, Art 48(3) - DL8.48.3.1',
            'DL8.48.3.2' => 'Oil, gas or hydrocarbons, Art 48(3) - DL8.48.3.2',
            'DL8.48.3.3' => 'Oil, gas or hydrocarbons, Art 48(3) - DL8.48.3.3',
            'DL8.48.8.1' => 'Goods the Cabinet specifies, Art 48(8) - DL8.48.8.1',
            'DL8.48.8.2' => 'Goods the Cabinet specifies, Art 48(8) - DL8.48.8.2',
        ];
    }

    /**
     * Federal Decree-Law No. 8 of 2017 Art 64(1): more than six months from
     * the supply, written off in the accounts, and the customer notified.
     */
    public function badDebtRules(): BadDebtRules
    {
        return new BadDebtRules(6, true, false, false, null, 'Federal Decree-Law No. 8 of 2017 Art 64(1)');
    }

    /**
     * Input tax is recovered in the period the invoice is held and the
     * consideration paid, or meant to be paid within six months of its
     * agreed date (Federal Decree-Law No. 8 of 2017 Art 55(1); Cabinet
     * Decision No. 52 of 2017 Art 54).
     */
    public function inputTaxOnPayment(): bool
    {
        return true;
    }

    public function manualEntries(): array
    {
        return ['tourist_refunds' => 'Tax refunds provided to tourists under the tax refunds for tourists scheme'];
    }

    public function draft(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, array $manual = []): VatReturnDraft
    {
        $tally = new VatReturnTally;
        $apart = ['AE' => 0.0, 'N' => 0.0, 'O' => 0.0];
        $missing = [];
        $unreadable = [];
        $unassigned = [];

        foreach ($this->sales->contributions($organizationId, $from, $to) as $row) {
            $invoice = $row['invoice'];

            if ($row['problem'] === 'rate') {
                $missing[] = (string) $invoice->invoice_number;

                continue;
            }

            if ($row['problem'] === 'unreadable') {
                $unreadable[] = (string) $invoice->invoice_number;

                continue;
            }

            $box = match ($row['code']) {
                'S' => '1'.$this->emirateBox($invoice, $unassigned),
                'Z' => '4',
                'E' => '5',
                default => null,
            };

            if ($box === null) {
                if ($row['code'] !== null) {
                    $apart[$row['code']] = ($apart[$row['code']] ?? 0.0) + $row['base'];
                }

                continue;
            }

            $tally->add($box, VatReturnEntry::SALE, (string) $invoice->id, (string) $invoice->invoice_number,
                $invoice->issue_date?->toDateString() ?? '', (string) $invoice->buyer_name, $row['base'], 0.0, $row['vat']);
        }

        // Bad-debt relief (Art 64(1)) takes the output tax on the unpaid share
        // off its emirate's box in Adjustments; a later payment gives it back.
        foreach ($this->badDebts->rows($organizationId, $from, $to) as $relief) {
            $invoice = $relief['invoice'];
            $rate = $this->vat->rate($invoice, $this->sales->currency()) ?? 0.0;
            $box = '1'.$this->emirateBox($invoice, $unassigned);

            foreach ($this->vat->categories($invoice) as $category) {
                if ($category['code'] !== 'S') {
                    continue;
                }

                $tally->add($box, VatReturnEntry::SALE, (string) $invoice->id, (string) $invoice->invoice_number, $invoice->issue_date?->toDateString() ?? '',
                    (string) $invoice->buyer_name, 0.0, -$relief['share'] * $category['base'] * $rate, -$relief['share'] * $category['tax'] * $rate);
            }
        }

        foreach ($this->purchases->rows($organizationId, $from, $to, asPaid: true) as $row) {
            $purchase = $row['purchase'];
            $entry = fn (string $box, float $amount, float $adjustment, float $vat) => $tally->add($box, VatReturnEntry::PURCHASE, (string) $purchase->id,
                (string) $purchase->supplier_invoice_number, $purchase->invoice_date->toDateString(), (string) $purchase->supplier?->name, $amount, $adjustment, $vat);
            $amount = $row['adjusts'] ? 0.0 : $row['base'];
            $adjustment = $row['adjusts'] ? $row['base'] : 0.0;

            // Recovered as paid, or taken back on a write-off: only the
            // recoverable side moves; what was due on a reverse charge stays.
            if ($row['kind'] !== PurchaseLedger::RECEIVED) {
                match ($row['treatment']) {
                    PurchaseTreatment::Standard, PurchaseTreatment::Import => $entry('9', 0.0, $adjustment, $row['deductible']),
                    PurchaseTreatment::ReverseCharge, PurchaseTreatment::DomesticReverseCharge, PurchaseTreatment::ImportInReturn => $entry('10', 0.0, 0.0, $row['deductible']),
                    default => null,
                };

                continue;
            }

            match ($row['treatment']) {
                PurchaseTreatment::Standard, PurchaseTreatment::Import => $entry('9', $amount, $adjustment, $row['deductible']),
                PurchaseTreatment::ReverseCharge, PurchaseTreatment::DomesticReverseCharge => [$entry('3', $row['base'], 0.0, $row['vat']), $entry('10', $row['base'], 0.0, $row['deductible'])],
                PurchaseTreatment::ImportInReturn => $row['adjusts']
                    ? [$entry('7', $row['base'], 0.0, $row['vat']), $entry('10', $row['base'], 0.0, $row['deductible'])]
                    : [$entry('6', $row['base'], 0.0, $row['vat']), $entry('10', $row['base'], 0.0, $row['deductible'])],
                default => null,
            };
        }

        $overdue = $this->purchases->overdue($organizationId, $to);

        $outputs = [];

        foreach (SupplyEmirate::BOXES as $suffix => $emirate) {
            $outputs[] = $tally->box('1'.$suffix, 'Standard rated supplies in '.$emirate);
        }

        if ($unassigned !== []) {
            $outputs[] = $tally->box('1x', 'Standard rated supplies, emirate not known');
        }

        $tourists = (float) ($manual['tourist_refunds'] ?? 0);
        $outputs[] = VatReturnTally::total('2', 'Tax refunds provided to tourists under the tax refunds for tourists scheme', null, null, -abs($tourists));
        $outputs[] = $tally->box('3', 'Supplies subject to the reverse charge provisions', adjustment: false);
        $outputs[] = $tally->box('4', 'Zero rated supplies', adjustment: false, vat: false);
        $outputs[] = $tally->box('5', 'Exempt supplies', adjustment: false, vat: false);
        $outputs[] = $tally->box('6', 'Goods imported into the UAE', adjustment: false);
        $outputs[] = $tally->box('7', 'Adjustments to goods imported into the UAE', adjustment: false);
        $outputs[] = VatReturnTally::total('8', 'Totals', VatReturnTally::sum($outputs, 'amount'), VatReturnTally::sum($outputs, 'adjustment'), VatReturnTally::sum($outputs, 'vat'));

        $inputs = [
            $tally->box('9', 'Standard rated expenses'),
            $tally->box('10', 'Supplies subject to the reverse charge provisions', adjustment: false),
        ];
        $inputs[] = VatReturnTally::total('11', 'Totals', VatReturnTally::sum($inputs, 'amount'), VatReturnTally::sum($inputs, 'adjustment'), VatReturnTally::sum($inputs, 'vat'));

        $due = (float) end($outputs)->vat;
        $recoverable = (float) end($inputs)->vat;

        $problems = [];
        $links = [];

        if ($missing !== []) {
            $problems[] = __('Left out, with no exchange rate to dirhams: :numbers.', ['numbers' => implode(', ', $missing)]);
        }

        if ($unreadable !== []) {
            $problems[] = __('Left out, in a tax category the UAE does not use: :numbers.', ['numbers' => implode(', ', $unreadable)]);
        }

        if ($overdue->isNotEmpty()) {
            $problems[] = __('Recovered on the intent to pay, and still unpaid six months after the agreed date: :numbers. If the supplier writes the debt off and tells you, record that on the purchase and its input tax comes off in that period (Federal Decree-Law No. 8 of 2017 Art 64(2)).', [
                'numbers' => $overdue->pluck('supplier_invoice_number')->implode(', '),
            ]);
            $links[__('Open the purchases')] = route('portal.books.purchases.index');
        }

        if ($unassigned !== []) {
            $problems[] = __('No emirate found for :numbers: give the branch, or the company, its emirate, and the sale moves to that emirate\'s box.', ['numbers' => implode(', ', array_unique($unassigned))]);
            $links[__('Give each branch its emirate')] = route('portal.branches.index');
        }

        return new VatReturnDraft(
            title: 'VAT return (VAT 201)',
            currency: $this->sales->currency(),
            sections: [
                'VAT on sales and all other outputs' => $outputs,
                'VAT on expenses and all other inputs' => $inputs,
                'Net VAT due' => [
                    VatReturnTally::total('12', 'Total value of due tax for the period', null, null, $due),
                    VatReturnTally::total('13', 'Total value of recoverable tax for the period', null, null, $recoverable),
                    VatReturnTally::total('14', 'Payable tax for the period', null, null, $due - $recoverable),
                ],
                'Not in the return' => [
                    VatReturnTally::total('ae-sales', 'Sales the buyer accounts for under the reverse charge', $apart['AE'], null, null),
                    VatReturnTally::total('margin-sales', 'Sales under the profit margin scheme', $apart['N'], null, null),
                    VatReturnTally::total('oos-sales', 'Out of scope sales, in no box', $apart['O'], null, null),
                ],
            ],
            notes: [
                __('A draft from your issued invoices and the purchases recorded in Books. Check it before you file it with the FTA.'),
                __('Each standard rated sale is in the emirate of the branch it was made from, or of the company where it has no branch (Cabinet Decision No. 52 of 2017 Art 72).'),
            ],
            problems: $problems,
            links: $links,
        );
    }

    /**
     * @param  list<string>  $unassigned
     */
    private function emirateBox(object $invoice, array &$unassigned): string
    {
        $suffix = $this->emirates->boxFor($invoice);

        if ($suffix === null) {
            $unassigned[] = (string) $invoice->invoice_number;

            return 'x';
        }

        return $suffix;
    }
}
