<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Core\Organization;
use App\Models\Sales\Contact;
use App\Models\Sales\Invoice;
use App\Models\Sales\InvoiceLine;
use App\Models\Sales\Quotation;
use App\Models\Sales\SalesOrder;
use App\Services\Accounting\JournalService;
use App\Services\Compliance\CompliPayClient;
use App\Services\Core\NumberGeneratorService;
use App\Services\Inventory\StockService;
use App\Services\Tax\TaxCalculatorService;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private TaxCalculatorService $taxCalculator,
        private JournalService $journalService,
        private StockService $stockService,
        private NumberGeneratorService $numberGenerator,
        private CompliPayClient $compliPayClient
    ) {}

    /**
     * Create a new invoice.
     */
    public function create(array $data, array $lines): Invoice
    {
        return DB::transaction(function () use ($data, $lines) {
            $organization = Organization::findOrFail(auth()->user()->organization_id);
            $customer = Contact::findOrFail($data['customer_id']);

            // Generate invoice number
            if (empty($data['invoice_number'])) {
                $prefix = $data['invoice_type'] === Invoice::TYPE_CREDIT_NOTE ? 'CN' : 'INV';
                $data['invoice_number'] = $this->numberGenerator->generate($prefix);
            }

            // Set customer details
            $data['customer_name'] = $customer->getDisplayName();
            $data['customer_email'] = $customer->email;
            $data['customer_tax_number'] = $customer->tax_number;
            $data['billing_address'] = $data['billing_address'] ?? $customer->getBillingAddress();
            $data['shipping_address'] = $data['shipping_address'] ?? $customer->getShippingAddress();

            // Set defaults
            $data['currency_code'] = $data['currency_code'] ?? $customer->currency_code ?? $organization->base_currency;
            $data['exchange_rate'] = $data['exchange_rate'] ?? 1;
            $data['due_date'] = $data['due_date'] ?? now()->addDays($customer->payment_terms);

            // Determine compliance requirement
            $data['compliance_status'] = $this->determineComplianceStatus($organization);

            $invoice = Invoice::create($data);

            // Calculate taxes and create lines
            $taxResult = $this->taxCalculator->calculate(
                $organization,
                $lines,
                $data['place_of_supply'] ?? null
            );

            foreach ($lines as $index => $lineData) {
                $taxLine = $taxResult->lines[$index] ?? [];

                $invoice->lines()->create(array_merge($lineData, [
                    'tax_rate' => $taxLine['tax_rate'] ?? 0,
                    'tax_amount' => $taxLine['tax_amount'] ?? 0,
                    'tax_code' => $taxLine['tax_code'] ?? 'S',
                    'cgst_rate' => $taxLine['cgst_rate'] ?? 0,
                    'cgst_amount' => $taxLine['cgst_amount'] ?? 0,
                    'sgst_rate' => $taxLine['sgst_rate'] ?? 0,
                    'sgst_amount' => $taxLine['sgst_amount'] ?? 0,
                    'igst_rate' => $taxLine['igst_rate'] ?? 0,
                    'igst_amount' => $taxLine['igst_amount'] ?? 0,
                    'line_order' => $index,
                ]));
            }

            $invoice->recalculateTotals();

            return $invoice->load('lines', 'customer');
        });
    }

    /**
     * Update a draft invoice.
     */
    public function update(Invoice $invoice, array $data, ?array $lines = null): Invoice
    {
        if (!$invoice->isEditable()) {
            throw new \InvalidArgumentException('Only draft invoices can be updated.');
        }

        return DB::transaction(function () use ($invoice, $data, $lines) {
            // Optimistic locking check
            if (isset($data['version']) && $data['version'] !== $invoice->version) {
                throw new \App\Exceptions\ConcurrencyException(
                    'Invoice has been modified by another user.',
                    $invoice->version
                );
            }

            $invoice->update(array_merge($data, ['version' => $invoice->version + 1]));

            if ($lines !== null) {
                $organization = Organization::findOrFail($invoice->organization_id);
                $invoice->lines()->delete();

                $taxResult = $this->taxCalculator->calculate(
                    $organization,
                    $lines,
                    $invoice->place_of_supply
                );

                foreach ($lines as $index => $lineData) {
                    $taxLine = $taxResult->lines[$index] ?? [];

                    $invoice->lines()->create(array_merge($lineData, [
                        'tax_rate' => $taxLine['tax_rate'] ?? 0,
                        'tax_amount' => $taxLine['tax_amount'] ?? 0,
                        'tax_code' => $taxLine['tax_code'] ?? 'S',
                        'cgst_rate' => $taxLine['cgst_rate'] ?? 0,
                        'cgst_amount' => $taxLine['cgst_amount'] ?? 0,
                        'sgst_rate' => $taxLine['sgst_rate'] ?? 0,
                        'sgst_amount' => $taxLine['sgst_amount'] ?? 0,
                        'igst_rate' => $taxLine['igst_rate'] ?? 0,
                        'igst_amount' => $taxLine['igst_amount'] ?? 0,
                        'line_order' => $index,
                    ]));
                }

                $invoice->recalculateTotals();
            }

            return $invoice->fresh(['lines', 'customer']);
        });
    }

    /**
     * Send/post an invoice.
     */
    public function send(Invoice $invoice): Invoice
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only draft invoices can be sent.');
        }

        return DB::transaction(function () use ($invoice) {
            // Create journal entry
            $journal = $this->createJournalEntry($invoice);

            // Deduct inventory (if applicable)
            $this->deductInventory($invoice);

            // Submit to CompliPay (if required)
            if ($invoice->requiresCompliance()) {
                $this->submitToCompliPay($invoice);
            }

            $invoice->update([
                'status' => Invoice::STATUS_SENT,
                'journal_entry_id' => $journal->id,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Void an invoice.
     */
    public function void(Invoice $invoice, string $reason = ''): Invoice
    {
        if ($invoice->isPaid()) {
            throw new \InvalidArgumentException('Paid invoices cannot be voided. Create a credit note instead.');
        }

        if ($invoice->status === Invoice::STATUS_VOIDED) {
            throw new \InvalidArgumentException('Invoice is already voided.');
        }

        return DB::transaction(function () use ($invoice, $reason) {
            // Reverse journal entry
            if ($invoice->journal_entry_id) {
                $this->journalService->void($invoice->journalEntry, $reason);
            }

            // Return inventory
            $this->returnInventory($invoice);

            $invoice->update([
                'status' => Invoice::STATUS_VOIDED,
                'notes' => $invoice->notes . "\n\nVoided: " . $reason,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Create a credit note for an invoice.
     */
    public function createCreditNote(Invoice $originalInvoice, array $lines, ?string $reason = null): Invoice
    {
        if ($originalInvoice->isCreditNote()) {
            throw new \InvalidArgumentException('Cannot create credit note for a credit note.');
        }

        $data = [
            'invoice_type' => Invoice::TYPE_CREDIT_NOTE,
            'original_invoice_id' => $originalInvoice->id,
            'customer_id' => $originalInvoice->customer_id,
            'invoice_date' => now(),
            'due_date' => now(),
            'currency_code' => $originalInvoice->currency_code,
            'exchange_rate' => $originalInvoice->exchange_rate,
            'branch_id' => $originalInvoice->branch_id,
            'place_of_supply' => $originalInvoice->place_of_supply,
            'notes' => $reason ?? "Credit note for invoice {$originalInvoice->invoice_number}",
            'reference' => $originalInvoice->invoice_number,
        ];

        return $this->create($data, $lines);
    }

    /**
     * Convert quotation to invoice.
     */
    public function createFromQuotation(Quotation $quotation): Invoice
    {
        if (!$quotation->canBeConverted()) {
            throw new \InvalidArgumentException('Quotation must be accepted before conversion.');
        }

        $lines = $quotation->lines->map(fn($line) => [
            'product_id' => $line->product_id,
            'variant_id' => $line->variant_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_id' => $line->unit_id,
            'unit_price' => $line->unit_price,
            'discount_type' => $line->discount_type,
            'discount_value' => $line->discount_value,
            'tax_category_id' => $line->tax_category_id,
        ])->toArray();

        $invoice = $this->create([
            'customer_id' => $quotation->customer_id,
            'quotation_id' => $quotation->id,
            'invoice_date' => now(),
            'branch_id' => $quotation->branch_id,
            'currency_code' => $quotation->currency_code,
            'exchange_rate' => $quotation->exchange_rate,
            'discount_type' => $quotation->discount_type,
            'discount_value' => $quotation->discount_value,
            'salesperson_id' => $quotation->salesperson_id,
            'notes' => $quotation->notes,
            'terms_and_conditions' => $quotation->terms_and_conditions,
            'reference' => $quotation->reference,
        ], $lines);

        $quotation->update(['status' => Quotation::STATUS_CONVERTED]);

        return $invoice;
    }

    /**
     * Convert sales order to invoice.
     */
    public function createFromSalesOrder(SalesOrder $order, ?array $lineQuantities = null): Invoice
    {
        if (!$order->canBeInvoiced()) {
            throw new \InvalidArgumentException('Sales order cannot be invoiced in current status.');
        }

        $lines = $order->lines
            ->filter(fn($line) => $line->getRemainingToInvoice() > 0)
            ->map(function ($line) use ($lineQuantities) {
                $quantity = $lineQuantities[$line->id] ?? $line->getRemainingToInvoice();

                return [
                    'product_id' => $line->product_id,
                    'variant_id' => $line->variant_id,
                    'description' => $line->description,
                    'quantity' => $quantity,
                    'unit_id' => $line->unit_id,
                    'unit_price' => $line->unit_price,
                    'discount_type' => $line->discount_type,
                    'discount_value' => $line->discount_value,
                    'tax_category_id' => $line->tax_category_id,
                    'warehouse_id' => $line->warehouse_id,
                ];
            })->toArray();

        if (empty($lines)) {
            throw new \InvalidArgumentException('No items available to invoice.');
        }

        $invoice = $this->create([
            'customer_id' => $order->customer_id,
            'sales_order_id' => $order->id,
            'invoice_date' => now(),
            'branch_id' => $order->branch_id,
            'currency_code' => $order->currency_code,
            'exchange_rate' => $order->exchange_rate,
            'discount_type' => $order->discount_type,
            'discount_value' => $order->discount_value,
            'salesperson_id' => $order->salesperson_id,
            'notes' => $order->notes,
            'reference' => $order->reference,
        ], $lines);

        // Update invoiced quantities on order lines
        foreach ($invoice->lines as $invoiceLine) {
            if ($invoiceLine->product_id) {
                $orderLine = $order->lines()
                    ->where('product_id', $invoiceLine->product_id)
                    ->where('variant_id', $invoiceLine->variant_id)
                    ->first();

                if ($orderLine) {
                    $orderLine->increment('quantity_invoiced', $invoiceLine->quantity);
                }
            }
        }

        // Update order status
        $progress = $order->fresh()->getFulfillmentProgress();
        if ($progress['invoice_percentage'] >= 100) {
            $order->update(['status' => SalesOrder::STATUS_INVOICED]);
        }

        return $invoice;
    }

    /**
     * Create journal entry for invoice.
     */
    protected function createJournalEntry(Invoice $invoice): \App\Models\Accounting\JournalEntry
    {
        $customer = $invoice->customer;
        $receivableAccountId = $customer->receivable_account_id ?? config('erp.default_accounts.receivable');

        $lines = [];

        // Debit: Accounts Receivable
        $lines[] = [
            'account_id' => $receivableAccountId,
            'description' => "Invoice {$invoice->invoice_number} - {$customer->getDisplayName()}",
            'debit' => $invoice->total,
            'credit' => 0,
            'contact_id' => $customer->id,
        ];

        // Credit: Sales/Income accounts
        foreach ($invoice->lines as $line) {
            $accountId = $line->account_id ?? $line->product?->income_account_id ?? config('erp.default_accounts.sales');

            $lines[] = [
                'account_id' => $accountId,
                'description' => $line->description,
                'debit' => 0,
                'credit' => $line->subtotal,
            ];
        }

        // Credit: Tax liability
        if ($invoice->tax_amount > 0) {
            $lines[] = [
                'account_id' => config('erp.default_accounts.tax_payable'),
                'description' => "VAT/GST on Invoice {$invoice->invoice_number}",
                'debit' => 0,
                'credit' => $invoice->tax_amount,
            ];
        }

        return $this->journalService->create([
            'entry_date' => $invoice->invoice_date,
            'reference' => $invoice->invoice_number,
            'description' => "Sales Invoice - {$customer->getDisplayName()}",
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
        ], $lines);
    }

    /**
     * Deduct inventory for invoice lines.
     */
    protected function deductInventory(Invoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if ($line->product_id && $line->product?->track_inventory && $line->warehouse_id) {
                $this->stockService->recordSale(
                    productId: $line->product_id,
                    warehouseId: $line->warehouse_id,
                    quantity: $line->quantity,
                    variantId: $line->variant_id,
                    referenceNumber: $invoice->invoice_number,
                    referenceId: $invoice->id
                );
            }
        }
    }

    /**
     * Return inventory for voided invoice.
     */
    protected function returnInventory(Invoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if ($line->product_id && $line->product?->track_inventory && $line->warehouse_id) {
                $this->stockService->recordMovement(
                    productId: $line->product_id,
                    warehouseId: $line->warehouse_id,
                    movementType: 'return_in',
                    direction: 'in',
                    quantity: $line->quantity,
                    unitCost: $line->product->cost_price ?? 0,
                    variantId: $line->variant_id,
                    referenceType: 'invoice',
                    referenceId: $invoice->id,
                    referenceNumber: $invoice->invoice_number . '-VOID',
                    notes: 'Inventory returned - invoice voided'
                );
            }
        }
    }

    /**
     * Submit invoice to CompliPay.
     */
    protected function submitToCompliPay(Invoice $invoice): void
    {
        try {
            $result = $this->compliPayClient->submitInvoice($invoice);

            $invoice->update([
                'compliance_status' => $result->status,
                'compliance_uuid' => $result->uuid,
                'compliance_hash' => $result->hash,
                'compliance_qr_code' => $result->qrCode,
                'compliance_response' => $result->response,
                'compliance_submitted_at' => now(),
            ]);
        } catch (\Exception $e) {
            $invoice->update([
                'compliance_status' => Invoice::COMPLIANCE_REJECTED,
                'compliance_response' => ['error' => $e->getMessage()],
            ]);

            throw $e;
        }
    }

    /**
     * Determine if compliance is required.
     */
    protected function determineComplianceStatus(Organization $organization): string
    {
        if (!$organization->requiresCompliance()) {
            return Invoice::COMPLIANCE_NOT_APPLICABLE;
        }

        return Invoice::COMPLIANCE_PENDING;
    }

    /**
     * Mark overdue invoices.
     */
    public function markOverdueInvoices(): int
    {
        return Invoice::whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])
            ->where('due_date', '<', now())
            ->update(['status' => Invoice::STATUS_OVERDUE]);
    }
}
