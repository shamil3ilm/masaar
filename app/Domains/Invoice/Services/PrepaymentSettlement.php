<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoicePrepayment;
use Illuminate\Support\Collection;

/**
 * Prepayment invoices (type 386) and the final invoices that settle them.
 *
 * An advance is invoiced when it is received, and its VAT is due then; the
 * final invoice states the whole supply and its VAT, and takes off what was
 * prepaid, category by category, so the buyer pays the rest. A prepayment is
 * settled once.
 */
class PrepaymentSettlement
{
    /**
     * The organization's issued prepayment invoice by that number, if it
     * can be settled: issued and not refused, not settled by another
     * invoice that stands, issued to the final invoice's buyer when that is
     * given, and not credited in full.
     *
     * @param  array{buyer_vat_number?: ?string, buyer_id?: ?string, buyer_id_scheme?: ?string}|null  $buyer  the final invoice's buyer
     * @return Invoice|string The invoice, or why it cannot be settled
     */
    public function settleable(string $organizationId, string $number, string $currency, ?array $buyer = null): Invoice|string
    {
        $invoice = Invoice::acrossTenants()
            ->with(['lines', 'charges'])
            ->where('org_id', $organizationId)
            ->where('invoice_number', $number)
            ->first();

        return match (true) {
            $invoice === null => __('There is no invoice :number.', ['number' => $number]),
            $invoice->document_type !== DocumentType::Prepayment => __('Invoice :number is not a prepayment invoice.', ['number' => $number]),
            $invoice->hash === null || in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Rejected], true) => __('Prepayment invoice :number has not been issued.', ['number' => $number]),
            strtoupper((string) $invoice->currency) !== strtoupper($currency) => __('Prepayment invoice :number is in :currency.', ['number' => $number, 'currency' => $invoice->currency]),
            $this->settled($invoice) => __('Prepayment invoice :number is already settled.', ['number' => $number]),
            $buyer !== null && ! $this->sameBuyer($invoice, $buyer) => __('Prepayment invoice :number was issued to another buyer.', ['number' => $number]),
            $this->prepaid($invoice) === [] => __('Prepayment invoice :number has been credited in full, so nothing is left to settle.', ['number' => $number]),
            default => $invoice,
        };
    }

    /**
     * What a prepayment invoice prepaid, per tax category: its base after
     * its discount and charges, and the VAT on it, adding up to the VAT it
     * stated.
     *
     * @return list<array{tax_category: string, tax_rate: string, exempt_code: ?string, exempt_reason: ?string, taxable_amount: string, tax_amount: string}>
     */
    public function prepaid(Invoice $prepayment): array
    {
        // What credit notes against it took back, category by category: a
        // prepayment credited in part settles only what is left of it. Its
        // taxable amounts and VAT then still add up to the prepaid amount
        // the final invoice states (BR-KSA-80; Detailed Guidelines 8.1(c)-(d)),
        // and the VAT the advance invoice accounted for is not taken off a
        // second time.
        $credited = [];

        foreach ($this->creditNotes($prepayment) as $note) {
            foreach ($this->categories($note) as $key => $category) {
                $credited[$key] = [
                    bcadd($credited[$key][0] ?? '0', $category['taxable_amount'], 2),
                    bcadd($credited[$key][1] ?? '0', $category['tax_amount'], 2),
                ];
            }
        }

        $prepaid = [];

        foreach ($this->categories($prepayment) as $key => $category) {
            $taxable = bcsub($category['taxable_amount'], $credited[$key][0] ?? '0', 2);
            $tax = bcsub($category['tax_amount'], $credited[$key][1] ?? '0', 2);

            if (bccomp($taxable, '0', 2) > 0 || bccomp($tax, '0', 2) > 0) {
                $prepaid[] = [...$category, 'taxable_amount' => $taxable, 'tax_amount' => $tax];
            }
        }

        return $prepaid;
    }

    /**
     * A document's taxable amounts and VAT per tax category, keyed by
     * category, rate and exemption.
     *
     * @return array<string, array{tax_category: string, tax_rate: string, exempt_code: ?string, exempt_reason: ?string, taxable_amount: string, tax_amount: string}>
     */
    private function categories(Invoice $prepayment): array
    {
        $nets = [];
        $rates = [];
        $codes = [];
        $charges = [];

        foreach ($prepayment->lines as $line) {
            $key = $this->key((string) ($line->tax_category ?: 'S'), (string) $line->tax_rate, $line->exempt_code);
            $nets[$key] = ($nets[$key] ?? 0.0) + (float) ($line->net_amount ?? bcsub((string) $line->line_total, (string) $line->tax_amount, 2));
            $rates[$key] = (float) $line->tax_rate;
            $codes[$key] ??= [(string) ($line->tax_category ?: 'S'), $line->exempt_code, $line->exempt_reason];
        }

        foreach ($prepayment->charges as $charge) {
            $key = $this->key((string) $charge->tax_category, (string) $charge->tax_rate, $charge->exempt_code);
            $charges[$key] = ($charges[$key] ?? 0.0) + (float) $charge->net_amount;
            $rates[$key] = (float) $charge->tax_rate;
            $codes[$key] ??= [(string) $charge->tax_category, $charge->exempt_code, $charge->exempt_reason];
        }

        $prepaid = [];

        foreach (InvoiceTotals::categories($nets, $rates, (float) $prepayment->discount_amount, (float) $prepayment->tax_amount, $charges) as $key => $category) {
            [$code, $exemptCode, $exemptReason] = $codes[$key];

            $prepaid[$key] = [
                'tax_category' => $code,
                'tax_rate' => number_format($rates[$key], 2, '.', ''),
                'exempt_code' => $exemptCode,
                'exempt_reason' => $exemptReason,
                'taxable_amount' => number_format($category['base'], 2, '.', ''),
                'tax_amount' => number_format($category['tax'], 2, '.', ''),
            ];
        }

        return $prepaid;
    }

    /**
     * The credit notes issued against the prepayment invoice that stand:
     * issued and not refused.
     *
     * @return Collection<int, Invoice>
     */
    private function creditNotes(Invoice $prepayment): Collection
    {
        return Invoice::acrossTenants()
            ->with(['lines', 'charges'])
            ->where('org_id', $prepayment->org_id)
            ->where('document_type', DocumentType::CreditNote->value)
            ->whereIn('billing_ref', array_values(array_filter([$prepayment->invoice_number, $prepayment->id])))
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Rejected->value])
            ->get();
    }

    /**
     * Whether the final invoice is to the buyer the advance was invoiced to:
     * by VAT number when the prepayment names one, else by its other
     * identifier. A prepayment that names no buyer - a walk-in simplified
     * sale - can be settled by anyone's invoice, as nothing says whose it is.
     *
     * @param  array{buyer_vat_number?: ?string, buyer_id?: ?string, buyer_id_scheme?: ?string}  $buyer
     */
    private function sameBuyer(Invoice $prepayment, array $buyer): bool
    {
        if (filled($prepayment->buyer_vat_number)) {
            return (string) $prepayment->buyer_vat_number === (string) ($buyer['buyer_vat_number'] ?? '');
        }

        if (filled($prepayment->buyer_id)) {
            return (string) $prepayment->buyer_id === (string) ($buyer['buyer_id'] ?? '')
                && (string) $prepayment->buyer_id_scheme === (string) ($buyer['buyer_id_scheme'] ?? '');
        }

        return true;
    }

    /**
     * Whether an invoice that stands - a draft included, until it is
     * discarded - already settles it.
     */
    private function settled(Invoice $prepayment): bool
    {
        return InvoicePrepayment::query()
            ->where('prepayment_invoice_id', $prepayment->id)
            ->whereHas('invoice', fn ($query) => $query->withoutGlobalScopes()->where('status', '!=', InvoiceStatus::Rejected->value))
            ->exists();
    }

    private function key(string $category, string $rate, ?string $exemptCode): string
    {
        return $category.'|'.(float) $rate.'|'.$exemptCode;
    }
}
