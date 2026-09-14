<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Turns a validated payload into a draft invoice with priced lines.
 *
 * The API and the ERP pipeline both create invoices through this, so there is
 * one set of totals for ZATCA to reconcile. The arithmetic is InvoiceTotals'.
 */
class InvoiceDrafter
{
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
     *
     * @param  string|null  $branchId  EGS unit issuing this invoice. Already
     *                                 confirmed to belong to the organization;
     *                                 decides which certificate signs it.
     */
    public function draft(array $data, string $organizationId, ?string $branchId = null): Invoice
    {
        $totals = InvoiceTotals::of($data['lines'], (string) ($data['discount_amount'] ?? '0'));

        return DB::transaction(function () use ($data, $organizationId, $branchId, $totals) {
            $invoice = Invoice::create([
                'org_id' => $organizationId,
                'branch_id' => $branchId,
                'invoice_number' => $data['invoice_number'],
                'type' => $data['type'],
                'document_type' => $data['document_type'],
                'status' => InvoiceStatus::Draft,
                'issue_date' => $data['issue_date'],
                'supply_date' => $data['supply_date'] ?? null,
                'currency' => $data['currency'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'payment_means_code' => $data['payment_means_code'] ?? '10',
                'buyer_name' => $data['buyer_name'],
                'buyer_vat_number' => $data['buyer_vat_number'] ?? null,
                'buyer_address' => $data['buyer_address'] ?? null,
                'billing_ref' => $data['billing_ref'] ?? null,
                'adjustment_reason' => $data['adjustment_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'erp_reference_id' => $data['erp_reference_id'] ?? null,
            ]);

            foreach (array_values($data['lines']) as $index => $line) {
                $priced = $totals->lines[$index];

                $invoice->lines()->create([
                    'description' => $line['description'],
                    'class_code' => $line['class_code'] ?? null,
                    'quantity' => (string) $line['quantity'],
                    'unit_code' => $line['unit_code'] ?? 'PCE',
                    'unit_price' => (string) $line['unit_price'],
                    'tax_rate' => $priced['rate'],
                    'tax_amount' => $priced['tax'],
                    'tax_category' => $line['tax_category'] ?? 'S',
                    'exempt_code' => $line['exempt_code'] ?? null,
                    'exempt_reason' => $line['exempt_reason'] ?? null,
                    'line_total' => $priced['total'],
                ]);
            }

            $invoice->update([
                'subtotal' => $totals->subtotal,
                'discount_amount' => $totals->discount,
                'tax_amount' => $totals->tax,
                'total' => $totals->total,
            ]);

            $this->audit->logCreated($invoice);

            return $invoice;
        });
    }
}
