<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\PurchaseInvoice;
use Carbon\CarbonImmutable;

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
 *   (b) has paid the consideration or part of it - which Cabinet Decision
 *   No. 52 of 2017 Art 54(2) treats as made where the person intends to pay
 *   within six months of the agreed date. A purchase recorded here is taken
 *   to be one the organization intends to pay so; one it does not must be
 *   left out until it is paid. Art 55(2) lets a missed one go in a later
 *   return, as does KSA Art 49(8): record it as received then.
 *
 * Void documents never count. A supplier's credit note takes away; one
 * that answers an invoice of an earlier period is an adjustment, as a sales
 * credit note is (KSA Art 54; Guideline for Tax Invoicing §7.1.1).
 */
class PurchaseLedger
{
    /**
     * @return list<array{purchase: PurchaseInvoice, treatment: PurchaseTreatment, adjusts: bool, base: float, vat: float, deductible: float, capital: float}>
     */
    public function rows(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $purchases = PurchaseInvoice::withoutTenantScope(fn () => PurchaseInvoice::query()
            ->where('org_id', $organizationId)
            ->where('status', PurchaseInvoice::RECORDED)
            ->where('received_on', '>=', $from->toDateString())
            ->where('received_on', '<', $to->addDay()->toDateString())
            ->with(['lines', 'supplier', 'original'])
            ->orderBy('received_on')
            ->orderBy('supplier_invoice_number')
            ->get());

        $rows = [];

        foreach ($purchases as $purchase) {
            $sign = $purchase->sign();
            $adjusts = $sign < 0 && $purchase->original !== null && $purchase->original->received_on->lt($from);
            $share = (float) $purchase->deductible_share / 100;
            $groups = [];

            foreach ($purchase->lines as $line) {
                $key = $line->treatment->value;
                $groups[$key] ??= ['purchase' => $purchase, 'treatment' => $line->treatment, 'adjusts' => $adjusts, 'base' => 0.0, 'vat' => 0.0, 'deductible' => 0.0, 'capital' => 0.0];
                $groups[$key]['base'] += $sign * (float) $line->net_tax;
                $groups[$key]['vat'] += $sign * (float) $line->vat_tax;
                $groups[$key]['deductible'] += $line->treatment->deductible() ? $sign * (float) $line->vat_tax * $share : 0.0;
                $groups[$key]['capital'] += $line->is_capital_asset ? $sign * (float) $line->net_tax : 0.0;
            }

            foreach ($groups as $group) {
                foreach (['base', 'vat', 'deductible', 'capital'] as $key) {
                    $group[$key] = round($group[$key], 2);
                }

                $rows[] = $group;
            }
        }

        return $rows;
    }
}
