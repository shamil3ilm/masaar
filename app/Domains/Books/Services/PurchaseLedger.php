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
 * A purchase belongs to the period of its document date - the date the
 * supplier's tax invoice, or the customs declaration, bears; the input VAT
 * on it is deducted in that period's return (KSA VAT Implementing
 * Regulations Art 49(7); UAE Cabinet Decision No. 52 of 2017 Art 55).
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
            ->where('invoice_date', '>=', $from->toDateString())
            ->where('invoice_date', '<', $to->addDay()->toDateString())
            ->with(['lines', 'supplier', 'original'])
            ->orderBy('invoice_date')
            ->orderBy('supplier_invoice_number')
            ->get());

        $rows = [];

        foreach ($purchases as $purchase) {
            $sign = $purchase->sign();
            $adjusts = $sign < 0 && $purchase->original !== null && $purchase->original->invoice_date->lt($from);
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
