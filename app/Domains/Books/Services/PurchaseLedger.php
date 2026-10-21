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
    public const RECEIVED = 'received';

    public const PAID = 'paid';

    public const WRITTEN_OFF = 'written_off';

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
                && $this->cost($purchase) - $this->paid($purchase, $to) > 0.005)
            ->values();
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
        $candidates = $this->recorded($organizationId)
            ->where('document_type', PurchaseInvoice::INVOICE)
            ->where('received_on', '<', $until)
            ->where(fn ($query) => $query->where('paid_later', true)
                ->orWhere(fn ($written) => $written->where('written_off_on', '>=', $from->toDateString())->where('written_off_on', '<', $until)))
            ->get();

        foreach ($candidates as $purchase) {
            $cost = $this->cost($purchase);

            if ($cost <= 0) {
                continue;
            }

            $received = CarbonImmutable::parse($purchase->received_on);
            $groups = $this->groups($purchase, withCredits: true);

            if ($purchase->paid_later) {
                // A payment before the invoice came is recovered when it came.
                $start = $received->gte($from) ? null : $from->subDay();
                $paid = $this->paid($purchase, $to) - ($start === null ? 0.0 : $this->paid($purchase, $start));

                if (abs($paid) > 0.005) {
                    foreach ($groups as $group) {
                        $rows[] = [...$group, 'kind' => self::PAID, 'adjusts' => false, 'base' => 0.0, 'vat' => 0.0, 'capital' => 0.0,
                            'deductible' => round($group['deductible'] * $paid / $cost, 2)];
                    }
                }
            }

            $writtenOff = $purchase->written_off_on === null ? null : CarbonImmutable::parse($purchase->written_off_on);

            if ($writtenOff !== null && $writtenOff->gte($from) && $writtenOff->lte($to)) {
                // Only what was recovered and not paid: nothing, where it was held until paid.
                $unpaid = $purchase->paid_later ? 0.0 : max(0.0, $cost - $this->paid($purchase, $writtenOff)) / $cost;

                if ($unpaid > 0) {
                    foreach ($groups as $group) {
                        $rows[] = [...$group, 'kind' => self::WRITTEN_OFF, 'adjusts' => true, 'vat' => 0.0, 'capital' => 0.0,
                            'base' => round(-$group['base'] * $unpaid, 2), 'deductible' => round(-$group['deductible'] * $unpaid, 2)];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Its lines by treatment: amount, VAT and the input tax it may deduct,
     * signed - with, for an invoice, its credit notes taken off when asked.
     *
     * @return list<array{purchase: PurchaseInvoice, treatment: PurchaseTreatment, base: float, vat: float, deductible: float, capital: float}>
     */
    private function groups(PurchaseInvoice $purchase, bool $withCredits = false): array
    {
        $documents = [$purchase, ...($withCredits ? $this->credits($purchase) : [])];
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

    /** What the invoice costs, in its currency, less its credit notes. */
    private function cost(PurchaseInvoice $purchase): float
    {
        return round((float) $purchase->gross - array_sum(array_map(fn (PurchaseInvoice $note): float => (float) $note->gross, $this->credits($purchase))), 2);
    }

    /** @return list<PurchaseInvoice> */
    private function credits(PurchaseInvoice $purchase): array
    {
        return $this->recorded((string) $purchase->org_id)->where('original_id', $purchase->id)->get()->all();
    }

    private function paid(PurchaseInvoice $purchase, CarbonImmutable $by): float
    {
        return (float) PurchasePayment::withoutTenantScope(fn () => PurchasePayment::query()
            ->where('purchase_invoice_id', $purchase->id)
            ->where('paid_on', '<', $by->addDay()->toDateString())
            ->sum('amount'));
    }

    /** @return Builder<PurchaseInvoice> */
    private function recorded(string $organizationId): Builder
    {
        return PurchaseInvoice::withoutTenantScope(fn () => PurchaseInvoice::query()
            ->where('org_id', $organizationId)
            ->where('status', PurchaseInvoice::RECORDED)
            ->with(['lines', 'supplier', 'original']));
    }
}
