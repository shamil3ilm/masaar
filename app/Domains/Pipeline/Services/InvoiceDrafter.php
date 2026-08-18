<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Turns an ERP payload into a draft invoice with priced lines.
 *
 * Money is computed with bcmath rather than floats throughout. Binary floating
 * point cannot represent most decimal fractions exactly, and ZATCA rejects an
 * invoice whose totals do not reconcile to the halalah — so a rounding error
 * here is a rejected tax document, not a display artefact.
 */
class InvoiceDrafter
{
    /**
     * ZATCA's standard VAT rate, applied when a line does not state its own.
     */
    private const DEFAULT_TAX_RATE = '15';

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Create the invoice and its lines as one atomic unit.
     *
     * The transaction also holds the ICV allocation lock taken by
     * Invoice::creating until after the insert commits — without it the
     * counter's lock is released early and two concurrent requests can
     * allocate the same value.
     */
    public function draft(array $data, string $organizationId): Invoice
    {
        return DB::transaction(function () use ($data, $organizationId) {
            $invoice = Invoice::create([
                'organization_id' => $organizationId,
                'invoice_number' => $data['invoice_number'],
                'type' => $data['type'],
                'document_type' => $data['document_type'],
                'status' => InvoiceStatus::Draft,
                'issue_date' => $data['issue_date'],
                'supply_date' => $data['supply_date'] ?? null,
                'currency' => $data['currency'] ?? 'SAR',
                'payment_means_code' => $data['payment_means_code'] ?? '10',
                'buyer_name' => $data['buyer_name'],
                'buyer_vat_number' => $data['buyer_vat_number'] ?? null,
                'buyer_address' => $data['buyer_address'] ?? null,
                'billing_reference_id' => $data['billing_reference_id'] ?? null,
                'adjustment_reason' => $data['adjustment_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'erp_reference_id' => $data['erp_reference_id'] ?? null,
            ]);

            $totals = $this->addLines($invoice, $data['lines']);
            $discount = (string) ($data['discount_amount'] ?? '0');

            $invoice->update([
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $discount,
                'tax_amount' => $totals['tax'],
                // Discount applies to the net amount, before tax is added.
                'total' => bcadd(bcsub($totals['subtotal'], $discount, 2), $totals['tax'], 2),
            ]);

            $this->audit->logCreated($invoice);

            return $invoice;
        });
    }

    /**
     * Price and persist each line, returning the invoice-level totals.
     *
     * @return array{subtotal: string, tax: string}
     */
    private function addLines(Invoice $invoice, array $lines): array
    {
        $subtotal = '0';
        $tax = '0';

        foreach ($lines as $line) {
            $quantity = (string) $line['quantity'];
            $unitPrice = (string) $line['unit_price'];
            $taxRate = (string) ($line['tax_rate'] ?? self::DEFAULT_TAX_RATE);

            $lineSubtotal = bcmul($quantity, $unitPrice, 2);
            // Four digits through the multiply so the division rounds once, at
            // the end, rather than compounding a half-halalah per line.
            $lineTax = bcdiv(bcmul($lineSubtotal, $taxRate, 4), '100', 2);

            $invoice->lines()->create([
                'description' => $line['description'],
                'item_classification_code' => $line['item_classification_code'] ?? null,
                'quantity' => $quantity,
                'unit_code' => $line['unit_code'] ?? 'PCE',
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'tax_category' => $line['tax_category'] ?? 'S',
                'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
                'tax_exemption_reason' => $line['tax_exemption_reason'] ?? null,
                'line_total' => bcadd($lineSubtotal, $lineTax, 2),
            ]);

            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
            $tax = bcadd($tax, $lineTax, 2);
        }

        return ['subtotal' => $subtotal, 'tax' => $tax];
    }
}
