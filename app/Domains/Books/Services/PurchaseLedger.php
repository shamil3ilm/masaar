<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Domains\Books\Models\PurchasePayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The organization's purchases for a period as its VAT return reads them:
 * one row per document and VAT treatment, in the tax currency, signed, so
 * each box can be traced back to the documents behind it.
 *
 * A purchase belongs to the period of the day it was received
 * (received_on), which is never before the document's date:
 *
 *   KSA VAT Implementing Regulations Art 49: input tax may be deducted only
 *   where the person holds the tax invoice or other evidence of it
 *   (Art 49(7)), in the tax period that includes the date of supply or a
 *   later one (Art 49(8)) - so at the earliest in the period in which both
 *   the supply has happened and the invoice is held. The document's date
 *   stands for the supply; received_on, never earlier, for holding it.
 *
 *   UAE Federal Decree-Law No. 8 of 2017 Art 55(1): recoverable input tax is
 *   deducted in the return of the first tax period in which the person (a)
 *   has received and keeps the tax invoice, or the import documents, and
 *   (b) has paid the consideration or part of it. Cabinet Decision No. 52
 *   of 2017 Art 54(2) treats it as paid to the extent the person intends to
 *   pay before six months after the agreed date for payment; Art 54(1)
 *   recovers, in a period, the input tax on the portion of the
 *   consideration paid in that period. So where input tax depends on
 *   payment ($asPaid, the country's VatReturn::inputTaxOnPayment), a
 *   purchase the organization will not pay within six months of its agreed
 *   date (paid_later) is reported when received with nothing recovered, and
 *   recovered as it is paid: in each period, its input tax times what was
 *   paid in that period over what it costs (a payment before the invoice
 *   came counts when it came). Art 55(2) and KSA Art 49(8) let a missed one
 *   go in a later return: record it as received then.
 *
 *   Decree-Law Art 64(2): where the supplier has written off the unpaid
 *   consideration and told the organization (written_off_on), the input tax
 *   recovered on the unpaid part is taken back in that period. The texts
 *   ask for no other reversal of input tax recovered on an intent to pay.
 *
 * Void documents never count. A supplier's credit note takes away; one
 * that answers an invoice of an earlier period is an adjustment, as a sales
 * credit note is (KSA Art 54; Guideline for Tax Invoicing §7.1.1). A credit
 * note against a purchase recovered as paid lowers what is recovered on its
 * payments instead.
 */
class PurchaseLedger
{
    public function __construct(
        private readonly VatPeriods $periods,
    ) {}

    public const RECEIVED = 'received';

    public const PAID = 'paid';

    public const WRITTEN_OFF = 'written_off';

    /** Input tax given back on a purchase unpaid a set time after its supply. */
    public const UNPAID = 'unpaid';

    /** Input tax given back that way, reclaimed as the purchase is paid. */
    public const RECLAIMED = 'reclaimed';

    /**
     * @return list<array{purchase: PurchaseInvoice, treatment: PurchaseTreatment, kind: string, adjusts: bool, base: float, vat: float, deductible: float, capital: float}>
     */
    public function rows(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, bool $asPaid = false): array
    {
        $purchases = $this->recorded($organizationId)
            ->where('received_on', '>=', $from->toDateString())
            ->where('received_on', '<', $to->addDay()->toDateString())
            ->orderBy('received_on')
            ->orderBy('supplier_invoice_number')
            ->get();

        $rows = [];

        foreach ($purchases as $purchase) {
            $held = $asPaid && $this->heldUntilPaid($purchase);
            $adjusts = $purchase->sign() < 0 && $purchase->original !== null && $purchase->original->received_on->lt($from);

            foreach ($this->groups($purchase) as $group) {
                $rows[] = [...$group, 'kind' => self::RECEIVED, 'adjusts' => $adjusts, 'deductible' => $held ? 0.0 : $group['deductible']];
            }
        }

        return $asPaid ? [...$rows, ...$this->recoveries($organizationId, $from, $to)] : $rows;
    }

    /**
     * Purchases whose input tax was recovered on an intent to pay and that,
     * six months after their agreed date for payment, are still unpaid as at
     * the period's end - for the return to point out, since only a
     * supplier's write-off notice (Art 64(2)) takes the tax back.
     *
     * @return Collection<int, PurchaseInvoice>
     */
    public function overdue(string $organizationId, CarbonImmutable $to): Collection
    {
        return $this->recorded($organizationId)
            ->where('document_type', PurchaseInvoice::INVOICE)
            ->where('paid_later', false)
            ->whereNull('written_off_on')
            ->where('received_on', '<', $to->addDay()->toDateString())
            ->get()
            ->filter(fn (PurchaseInvoice $purchase): bool => CarbonImmutable::parse($purchase->due_on ?? $purchase->invoice_date)->addMonthsNoOverflow(6)->lt($to)
                && $this->cost($purchase, $to) - $this->paid($purchase, $to) > 0.005)
            ->values();
    }

    /**
     * Input tax given back on supplier invoices still not paid in full
     * $months after their supply, and reclaimed as they are paid after that.
     *
     *   KSA VAT Implementing Regulations Art 40(10): "Any Taxable Person who
     *   has deducted Input Tax in respect of a Supply received by that
     *   Person, but has failed to make payment in full after a period of
     *   twelve months from the date of Supply, must reduce the Input Tax
     *   deduction by the amount of Tax calculated on the Consideration not
     *   paid at that date." Art 40(11): where it "subsequently makes payment
     *   of the Consideration, the Input Tax deduction may be increased
     *   correspondingly to reflect the Tax calculated on the Consideration
     *   paid."
     *
     * Read so: the document's date stands for the supply, as it does for
     * deducting (the class's note on Art 49); "that date" is the supply date
     * plus $months, and the reduction is made in the return of the period
     * it falls in - or the next open one, where that period is filed
     * (VatPeriods::openDay), as every late event here is. What is unpaid
     * is a share of what the invoice costs after the credit notes received
     * by then, and that share of its deducted input tax is given back. Each
     * later payment reclaims its share in the period it is counted in.
     *
     * Only standard-rated domestic purchases: the consideration on them
     * carries the supplier's tax. VAT on an import is paid at customs, and
     * a reverse charge is the buyer's own, neither in what the supplier is
     * owed. A purchase held until paid never deducted the unpaid part, and
     * a written-off one is answered by its write-off.
     *
     * The given-back amount as at a day is the deductible tax times the
     * unpaid share on it, from the day it counts; a period takes what
     * changed between the day before it and its last day, so the reduction
     * and each reclaim land once, in their own period.
     *
     * @return list<array<string, mixed>> as rows(): kind UNPAID below zero, RECLAIMED above
     */
    public function unpaidAdjustments(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, int $months): array
    {
        $rows = [];
        $before = $from->subDay();

        foreach ($this->unpaidCandidates($organizationId, $to, $months) as $purchase) {
            $now = $this->givenBack($purchase, $to, $months);
            $then = $this->givenBack($purchase, $before, $months);

            foreach ($now as $key => $group) {
                $base = round($group['base'] - ($then[$key]['base'] ?? 0.0), 2);
                $tax = round($group['deductible'] - ($then[$key]['deductible'] ?? 0.0), 2);

                if (abs($tax) >= 0.005) {
                    $rows[] = [...$group, 'kind' => $tax > 0 ? self::UNPAID : self::RECLAIMED, 'adjusts' => true,
                        'base' => -$base + 0.0, 'vat' => 0.0, 'capital' => 0.0, 'deductible' => -$tax + 0.0];
                }
            }
        }

        return $rows;
    }

    /**
     * Supplier invoices not paid in full $months after their supply, as at
     * a day: each with the day its tax was given back, what is unpaid, and
     * the input tax that is given back for it.
     *
     * @return list<array{purchase: PurchaseInvoice, due: CarbonImmutable, unpaid: float, vat: float}>
     */
    public function unpaidOver(string $organizationId, CarbonImmutable $asAt, int $months): array
    {
        $rows = [];

        foreach ($this->unpaidCandidates($organizationId, $asAt, $months) as $purchase) {
            $vat = round(array_sum(array_column($this->givenBack($purchase, $asAt, $months), 'deductible')), 2);

            if ($vat >= 0.005) {
                $rows[] = [
                    'purchase' => $purchase,
                    'due' => $this->unpaidFrom($purchase, $months),
                    'unpaid' => round($this->cost($purchase, $asAt) - $this->paid($purchase, $asAt), 2),
                    'vat' => $vat,
                ];
            }
        }

        return $rows;
    }

    /** @return Collection<int, PurchaseInvoice> */
    private function unpaidCandidates(string $organizationId, CarbonImmutable $by, int $months): Collection
    {
        return $this->recorded($organizationId)
            ->where('document_type', PurchaseInvoice::INVOICE)
            ->where('paid_later', false)
            ->whereNull('written_off_on')
            ->where('invoice_date', '<', $by->subMonthsNoOverflow($months)->addDay()->toDateString())
            ->orderBy('invoice_date')
            ->get()
            ->filter(fn (PurchaseInvoice $purchase): bool => $purchase->lines->contains(fn ($line): bool => $line->treatment === PurchaseTreatment::Standard))
            ->values();
    }

    /** The day its unpaid input tax is given back: $months after its supply, or the next open day. */
    private function unpaidFrom(PurchaseInvoice $purchase, int $months): CarbonImmutable
    {
        return $this->periods->openDay((string) $purchase->org_id, CarbonImmutable::parse($purchase->invoice_date)->addMonthsNoOverflow($months));
    }

    /**
     * The standard-rated input tax given back as at the end of a day, and
     * its amount: nothing before it counts, then the deductible tax times
     * what is unpaid over what the invoice costs.
     *
     * @return array<string, array<string, mixed>>
     */
    private function givenBack(PurchaseInvoice $purchase, CarbonImmutable $by, int $months): array
    {
        if ($this->unpaidFrom($purchase, $months)->gt($by)) {
            return [];
        }

        $cost = $this->cost($purchase, $by);
        $unpaid = $cost <= 0 ? 0.0 : min(1.0, max(0.0, $cost - $this->paid($purchase, $by)) / $cost);
        $groups = [];

        foreach ($this->groups($purchase, $by) as $group) {
            if ($group['treatment'] === PurchaseTreatment::Standard) {
                $groups[$group['treatment']->value] = [...$group, 'base' => round($group['base'] * $unpaid, 2), 'deductible' => round($group['deductible'] * $unpaid, 2)];
            }
        }

        return $groups;
    }

    /**
     * What is recovered in the period on purchases held until paid, and what
     * is taken back on purchases written off.
     *
     * @return list<array<string, mixed>>
     */
    private function recoveries(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $until = $to->addDay()->toDateString();
        $before = $from->subDay();
        $candidates = $this->recorded($organizationId)
            ->where('document_type', PurchaseInvoice::INVOICE)
            ->where('received_on', '<', $until)
            ->where(fn ($query) => $query->where('paid_later', true)
                ->orWhere(fn ($written) => $written->where('written_off_on', '>=', $from->toDateString())->where('written_off_on', '<', $until)))
            ->get();

        foreach ($candidates as $purchase) {
            if ($purchase->paid_later) {
                // Recovered as paid: what it is owed by the period's end, less
                // what it was owed before it began. A credit note after
                // payment lowers what is owed, so it is taken back here.
                $now = $this->entitled($purchase, $to);
                $then = $this->entitled($purchase, $before);

                foreach ($now as $key => $group) {
                    $recovered = round($group['deductible'] - ($then[$key]['deductible'] ?? 0.0), 2);

                    if (abs($recovered) >= 0.005) {
                        $rows[] = [...$group, 'kind' => self::PAID, 'adjusts' => false, 'base' => 0.0, 'vat' => 0.0, 'capital' => 0.0, 'deductible' => $recovered + 0.0];
                    }
                }
            }

            $writtenOff = $purchase->written_off_on === null ? null : CarbonImmutable::parse($purchase->written_off_on);

            if ($writtenOff !== null && $writtenOff->gte($from) && $writtenOff->lte($to) && ! $purchase->paid_later) {
                // Only what was recovered and not paid; held until paid, it
                // never recovered the unpaid part.
                $cost = $this->cost($purchase, $writtenOff);
                $unpaid = $cost <= 0 ? 0.0 : max(0.0, $cost - $this->paid($purchase, $writtenOff)) / $cost;

                if ($unpaid > 0) {
                    foreach ($this->groups($purchase, $writtenOff) as $group) {
                        $rows[] = [...$group, 'kind' => self::WRITTEN_OFF, 'adjusts' => true, 'vat' => 0.0, 'capital' => 0.0,
                            'base' => round(-$group['base'] * $unpaid, 2), 'deductible' => round(-$group['deductible'] * $unpaid, 2)];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * What an invoice recovered as paid is owed, by treatment, as at the end
     * of a day: its input tax after the credit notes received by then, times
     * what was paid by then over what it then costs - never more than all of
     * it (Cabinet Decision No. 52 of 2017 Art 54(1)). Nothing before the
     * invoice itself was received.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entitled(PurchaseInvoice $purchase, CarbonImmutable $by): array
    {
        if (CarbonImmutable::parse($purchase->received_on)->gt($by)) {
            return [];
        }

        $cost = $this->cost($purchase, $by);
        $share = $cost <= 0 ? 0.0 : min(1.0, max(0.0, $this->paid($purchase, $by)) / $cost);
        $groups = [];

        foreach ($this->groups($purchase, $by) as $group) {
            $groups[$group['treatment']->value] = [...$group, 'deductible' => round($group['deductible'] * $share, 2)];
        }

        return $groups;
    }

    /**
     * Its lines by treatment: amount, VAT and the input tax it may deduct,
     * signed - with, for an invoice, the credit notes against it received by
     * a day taken off when asked.
     *
     * @return list<array{purchase: PurchaseInvoice, treatment: PurchaseTreatment, base: float, vat: float, deductible: float, capital: float}>
     */
    private function groups(PurchaseInvoice $purchase, ?CarbonImmutable $creditsBy = null): array
    {
        $documents = [$purchase, ...($creditsBy !== null ? $this->credits($purchase, $creditsBy) : [])];
        $groups = [];

        foreach ($documents as $document) {
            $sign = $document->sign();
            $share = (float) $document->deductible_share / 100;

            foreach ($document->lines as $line) {
                $key = $line->treatment->value;
                $groups[$key] ??= ['purchase' => $purchase, 'treatment' => $line->treatment, 'base' => 0.0, 'vat' => 0.0, 'deductible' => 0.0, 'capital' => 0.0];
                $groups[$key]['base'] += $sign * (float) $line->net_tax;
                $groups[$key]['vat'] += $sign * (float) $line->vat_tax;
                $groups[$key]['deductible'] += $line->treatment->deductible() ? $sign * (float) $line->vat_tax * $share : 0.0;
                $groups[$key]['capital'] += $line->is_capital_asset ? $sign * (float) $line->net_tax : 0.0;
            }
        }

        return array_values(array_map(function (array $group): array {
            foreach (['base', 'vat', 'deductible', 'capital'] as $key) {
                $group[$key] = round($group[$key], 2) + 0.0;
            }

            return $group;
        }, $groups));
    }

    /** A credit note, or an invoice, recovered only as paid. */
    private function heldUntilPaid(PurchaseInvoice $purchase): bool
    {
        return $purchase->document_type === PurchaseInvoice::CREDIT_NOTE ? (bool) $purchase->original?->paid_later : (bool) $purchase->paid_later;
    }

    /** What the invoice costs, in its currency, less the credit notes received against it by a day. */
    private function cost(PurchaseInvoice $purchase, CarbonImmutable $by): float
    {
        return round((float) $purchase->gross - array_sum(array_map(fn (PurchaseInvoice $note): float => (float) $note->gross, $this->credits($purchase, $by))), 2);
    }

    /** @return list<PurchaseInvoice> */
    private function credits(PurchaseInvoice $purchase, CarbonImmutable $by): array
    {
        return $this->recorded((string) $purchase->org_id)->where('original_id', $purchase->id)
            ->where('received_on', '<', $by->addDay()->toDateString())->get()->all();
    }

    /**
     * What was paid on it by a day. A payment dated in a filed period is
     * taken account of from the next open day (VatPeriods::openDay), so it
     * moves an open period, never a filed one.
     */
    private function paid(PurchaseInvoice $purchase, CarbonImmutable $by): float
    {
        $payments = PurchasePayment::acrossTenants()
            ->where('purchase_invoice_id', $purchase->id)
            ->where('paid_on', '<', $by->addDay()->toDateString())
            ->get(['amount', 'paid_on']);

        return round((float) $payments
            ->filter(fn (PurchasePayment $payment): bool => $this->periods->openDay((string) $purchase->org_id, CarbonImmutable::parse($payment->paid_on))->lte($by))
            ->sum(fn (PurchasePayment $payment): float => (float) $payment->amount), 2);
    }

    /** @return Builder<PurchaseInvoice> */
    private function recorded(string $organizationId): Builder
    {
        return PurchaseInvoice::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('status', PurchaseInvoice::RECORDED)
            ->with(['lines', 'supplier', 'original']);
    }
}
