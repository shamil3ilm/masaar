<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Sales\Invoice;
use Illuminate\Support\Collection;

class InvoiceExportService
{
    public function __construct(
        private ExportService $exportService
    ) {}

    /**
     * Export invoices to CSV.
     */
    public function exportToCsv(Collection $invoices): string
    {
        $columns = [
            'invoice_number' => 'Invoice Number',
            'invoice_date' => 'Invoice Date',
            'due_date' => 'Due Date',
            'customer_name' => 'Customer',
            'status' => 'Status',
            'subtotal' => 'Subtotal',
            'tax_amount' => 'Tax',
            'total' => 'Total',
            'amount_paid' => 'Paid',
            'amount_due' => 'Due',
            'currency_code' => 'Currency',
        ];

        $data = $invoices->map(fn($invoice) => [
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'customer_name' => $invoice->customer_name,
            'status' => $invoice->status,
            'subtotal' => $invoice->subtotal,
            'tax_amount' => $invoice->tax_amount,
            'total' => $invoice->total,
            'amount_paid' => $invoice->amount_paid,
            'amount_due' => $invoice->amount_due,
            'currency_code' => $invoice->currency_code,
        ]);

        $filename = 'invoices_' . now()->format('Y-m-d_His');

        return $this->exportService->toCsv($data, $columns, $filename);
    }

    /**
     * Export single invoice to PDF.
     */
    public function exportToPdf(Invoice $invoice): string
    {
        $invoice->load(['customer', 'lines.product', 'lines.taxCategory', 'organization']);

        $data = [
            'invoice' => $invoice,
            'organization' => $invoice->organization,
            'customer' => $invoice->customer,
            'lines' => $invoice->lines,
        ];

        $filename = "invoice_{$invoice->invoice_number}";

        return $this->exportService->toPdf('exports.invoice', $data, $filename);
    }

    /**
     * Generate invoice data for PDF view.
     */
    public function getInvoiceData(Invoice $invoice): array
    {
        $invoice->load(['customer', 'lines.product', 'lines.taxCategory', 'organization', 'branch']);

        return [
            'invoice' => [
                'number' => $invoice->invoice_number,
                'date' => $invoice->invoice_date->format('M d, Y'),
                'due_date' => $invoice->due_date?->format('M d, Y'),
                'type' => $invoice->invoice_type,
                'status' => $invoice->status,
                'reference' => $invoice->reference,
                'notes' => $invoice->notes,
                'terms' => $invoice->terms_and_conditions,
            ],
            'organization' => [
                'name' => $invoice->organization->legal_name ?? $invoice->organization->name,
                'address' => $this->formatAddress($invoice->organization),
                'tax_number' => $invoice->organization->tax_number,
                'phone' => $invoice->organization->phone,
                'email' => $invoice->organization->email,
            ],
            'customer' => [
                'name' => $invoice->customer_name,
                'address' => $invoice->billing_address,
                'tax_number' => $invoice->customer_tax_number,
                'email' => $invoice->customer_email,
            ],
            'lines' => $invoice->lines->map(fn($line) => [
                'description' => $line->description,
                'quantity' => number_format((float) $line->quantity, 2),
                'unit_price' => number_format((float) $line->unit_price, 2),
                'discount' => number_format((float) $line->discount_amount, 2),
                'tax_rate' => $line->tax_rate . '%',
                'tax_amount' => number_format((float) $line->tax_amount, 2),
                'total' => number_format((float) $line->total, 2),
            ]),
            'totals' => [
                'subtotal' => number_format((float) $invoice->subtotal, 2),
                'discount' => number_format((float) $invoice->discount_amount, 2),
                'tax' => number_format((float) $invoice->tax_amount, 2),
                'total' => number_format((float) $invoice->total, 2),
                'paid' => number_format((float) $invoice->amount_paid, 2),
                'due' => number_format((float) $invoice->amount_due, 2),
                'currency' => $invoice->currency_code,
            ],
            'compliance' => [
                'qr_code' => $invoice->compliance_qr_code,
                'uuid' => $invoice->compliance_uuid,
                'hash' => $invoice->compliance_hash,
            ],
        ];
    }

    /**
     * Format organization address.
     */
    protected function formatAddress($entity): string
    {
        $parts = array_filter([
            $entity->address_line_1 ?? null,
            $entity->address_line_2 ?? null,
            $entity->city ?? null,
            $entity->state ?? null,
            $entity->postal_code ?? null,
            $entity->country_code ?? null,
        ]);

        return implode(', ', $parts);
    }
}
