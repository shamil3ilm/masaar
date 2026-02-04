<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Models\Core\Organization;
use App\Models\Purchase\Bill;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Sales\Contact;
use App\Services\Accounting\JournalService;
use App\Services\Core\NumberGeneratorService;
use App\Services\Inventory\StockService;
use App\Services\Tax\TaxCalculatorService;
use Illuminate\Support\Facades\DB;

class BillService
{
    public function __construct(
        private TaxCalculatorService $taxCalculator,
        private JournalService $journalService,
        private StockService $stockService,
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new bill.
     */
    public function create(array $data, array $lines): Bill
    {
        return DB::transaction(function () use ($data, $lines) {
            $organization = Organization::findOrFail(auth()->user()->organization_id);
            $supplier = Contact::findOrFail($data['supplier_id']);

            if (empty($data['bill_number'])) {
                $prefix = $data['bill_type'] === Bill::TYPE_DEBIT_NOTE ? 'DN' : 'BILL';
                $data['bill_number'] = $this->numberGenerator->generate($prefix);
            }

            $data['supplier_name'] = $supplier->getDisplayName();
            $data['supplier_tax_number'] = $supplier->tax_number;
            $data['supplier_address'] = $data['supplier_address'] ?? $supplier->getBillingAddress();

            $data['currency_code'] = $data['currency_code'] ?? $supplier->currency_code ?? $organization->base_currency;
            $data['exchange_rate'] = $data['exchange_rate'] ?? 1;
            $data['due_date'] = $data['due_date'] ?? now()->addDays($supplier->payment_terms);

            $bill = Bill::create($data);

            $taxResult = $this->taxCalculator->calculate(
                $organization,
                $lines,
                $data['place_of_supply'] ?? null
            );

            foreach ($lines as $index => $lineData) {
                $taxLine = $taxResult->lines[$index] ?? [];

                $bill->lines()->create(array_merge($lineData, [
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

            $bill->recalculateTotals();

            return $bill->load('lines', 'supplier');
        });
    }

    /**
     * Update a draft bill.
     */
    public function update(Bill $bill, array $data, ?array $lines = null): Bill
    {
        if (!$bill->isEditable()) {
            throw new \InvalidArgumentException('Only draft/pending bills can be updated.');
        }

        return DB::transaction(function () use ($bill, $data, $lines) {
            if (isset($data['version']) && $data['version'] !== $bill->version) {
                throw new \App\Exceptions\ConcurrencyException(
                    'Bill has been modified by another user.',
                    $bill->version
                );
            }

            $bill->update(array_merge($data, ['version' => $bill->version + 1]));

            if ($lines !== null) {
                $organization = Organization::findOrFail($bill->organization_id);
                $bill->lines()->delete();

                $taxResult = $this->taxCalculator->calculate(
                    $organization,
                    $lines,
                    $bill->place_of_supply
                );

                foreach ($lines as $index => $lineData) {
                    $taxLine = $taxResult->lines[$index] ?? [];

                    $bill->lines()->create(array_merge($lineData, [
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

                $bill->recalculateTotals();
            }

            return $bill->fresh(['lines', 'supplier']);
        });
    }

    /**
     * Approve a bill.
     */
    public function approve(Bill $bill): Bill
    {
        if (!in_array($bill->status, [Bill::STATUS_DRAFT, Bill::STATUS_PENDING], true)) {
            throw new \InvalidArgumentException('Only draft/pending bills can be approved.');
        }

        return DB::transaction(function () use ($bill) {
            $journal = $this->createJournalEntry($bill);

            $this->addInventory($bill);

            $bill->update([
                'status' => Bill::STATUS_APPROVED,
                'journal_entry_id' => $journal->id,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            return $bill->fresh();
        });
    }

    /**
     * Void a bill.
     */
    public function void(Bill $bill, string $reason = ''): Bill
    {
        if ($bill->isPaid()) {
            throw new \InvalidArgumentException('Paid bills cannot be voided. Create a debit note instead.');
        }

        return DB::transaction(function () use ($bill, $reason) {
            if ($bill->journal_entry_id) {
                $this->journalService->void($bill->journalEntry, $reason);
            }

            $this->reverseInventory($bill);

            $bill->update([
                'status' => Bill::STATUS_VOIDED,
                'notes' => $bill->notes . "\n\nVoided: " . $reason,
            ]);

            return $bill->fresh();
        });
    }

    /**
     * Create bill from purchase order.
     */
    public function createFromPurchaseOrder(PurchaseOrder $order, ?array $lineQuantities = null): Bill
    {
        if (!$order->canBeBilled()) {
            throw new \InvalidArgumentException('Purchase order cannot be billed in current status.');
        }

        $lines = $order->lines
            ->filter(fn($line) => $line->getRemainingToBill() > 0)
            ->map(function ($line) use ($lineQuantities) {
                $quantity = $lineQuantities[$line->id] ?? $line->getRemainingToBill();

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
            throw new \InvalidArgumentException('No items available to bill.');
        }

        $bill = $this->create([
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'bill_date' => now(),
            'branch_id' => $order->branch_id,
            'currency_code' => $order->currency_code,
            'exchange_rate' => $order->exchange_rate,
            'discount_type' => $order->discount_type,
            'discount_value' => $order->discount_value,
            'notes' => $order->notes,
            'reference' => $order->order_number,
        ], $lines);

        foreach ($bill->lines as $billLine) {
            if ($billLine->product_id) {
                $orderLine = $order->lines()
                    ->where('product_id', $billLine->product_id)
                    ->where('variant_id', $billLine->variant_id)
                    ->first();

                if ($orderLine) {
                    $orderLine->increment('quantity_billed', $billLine->quantity);
                }
            }
        }

        $progress = $order->fresh()->getReceivingProgress();
        if ($progress['billing_percentage'] >= 100) {
            $order->update(['status' => PurchaseOrder::STATUS_BILLED]);
        }

        return $bill;
    }

    /**
     * Create journal entry for bill.
     */
    protected function createJournalEntry(Bill $bill): \App\Models\Accounting\JournalEntry
    {
        $supplier = $bill->supplier;
        $payableAccountId = $supplier->payable_account_id ?? config('erp.default_accounts.payable');

        $lines = [];

        foreach ($bill->lines as $line) {
            $accountId = $line->account_id ?? $line->product?->expense_account_id ?? config('erp.default_accounts.expense');

            $lines[] = [
                'account_id' => $accountId,
                'description' => $line->description,
                'debit' => $line->subtotal,
                'credit' => 0,
            ];
        }

        if ($bill->tax_amount > 0) {
            $lines[] = [
                'account_id' => config('erp.default_accounts.tax_receivable'),
                'description' => "Input VAT/GST on Bill {$bill->bill_number}",
                'debit' => $bill->tax_amount,
                'credit' => 0,
            ];
        }

        $lines[] = [
            'account_id' => $payableAccountId,
            'description' => "Bill {$bill->bill_number} - {$supplier->getDisplayName()}",
            'debit' => 0,
            'credit' => $bill->total,
            'contact_id' => $supplier->id,
        ];

        return $this->journalService->create([
            'entry_date' => $bill->bill_date,
            'reference' => $bill->bill_number,
            'description' => "Purchase Bill - {$supplier->getDisplayName()}",
            'source_type' => Bill::class,
            'source_id' => $bill->id,
            'branch_id' => $bill->branch_id,
        ], $lines);
    }

    /**
     * Add inventory for bill lines.
     */
    protected function addInventory(Bill $bill): void
    {
        foreach ($bill->lines as $line) {
            if ($line->product_id && $line->product?->track_inventory && $line->warehouse_id) {
                $this->stockService->recordPurchase(
                    productId: $line->product_id,
                    warehouseId: $line->warehouse_id,
                    quantity: $line->quantity,
                    unitCost: $line->unit_price,
                    variantId: $line->variant_id,
                    referenceNumber: $bill->bill_number,
                    referenceId: $bill->id
                );
            }
        }
    }

    /**
     * Reverse inventory for voided bill.
     */
    protected function reverseInventory(Bill $bill): void
    {
        foreach ($bill->lines as $line) {
            if ($line->product_id && $line->product?->track_inventory && $line->warehouse_id) {
                $this->stockService->recordMovement(
                    productId: $line->product_id,
                    warehouseId: $line->warehouse_id,
                    movementType: 'return_out',
                    direction: 'out',
                    quantity: $line->quantity,
                    unitCost: $line->unit_price,
                    variantId: $line->variant_id,
                    referenceType: 'bill',
                    referenceId: $bill->id,
                    referenceNumber: $bill->bill_number . '-VOID',
                    notes: 'Inventory reversed - bill voided'
                );
            }
        }
    }

    /**
     * Mark overdue bills.
     */
    public function markOverdueBills(): int
    {
        return Bill::whereIn('status', [Bill::STATUS_APPROVED, Bill::STATUS_PARTIAL])
            ->where('due_date', '<', now())
            ->count();
    }
}
