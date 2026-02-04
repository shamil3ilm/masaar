<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Sales\Contact;
use App\Models\Sales\CustomerCredit;
use App\Models\Sales\Invoice;
use App\Models\Sales\PaymentAllocation;
use App\Models\Sales\PaymentReceived;
use App\Services\Accounting\JournalService;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private JournalService $journalService,
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new payment.
     */
    public function create(array $data, array $allocations = []): PaymentReceived
    {
        return DB::transaction(function () use ($data, $allocations) {
            // Generate payment number
            if (empty($data['payment_number'])) {
                $data['payment_number'] = $this->numberGenerator->generate('PAY');
            }

            // Calculate base amount
            $data['base_amount'] = bcmul(
                (string) $data['amount'],
                (string) ($data['exchange_rate'] ?? 1),
                4
            );

            $payment = PaymentReceived::create($data);

            // Allocate to invoices
            $totalAllocated = 0;
            foreach ($allocations as $allocation) {
                $invoice = Invoice::findOrFail($allocation['invoice_id']);
                $amount = min($allocation['amount'], (float) $invoice->amount_due);

                if ($amount > 0) {
                    $this->allocate($payment, $invoice, $amount);
                    $totalAllocated = bcadd((string) $totalAllocated, (string) $amount, 4);
                }
            }

            // Create customer credit for unallocated amount
            $unallocated = bcsub((string) $payment->amount, (string) $totalAllocated, 4);
            if (bccomp($unallocated, '0', 4) > 0) {
                $this->createCustomerCredit($payment, (float) $unallocated);
            }

            return $payment->load('allocations.invoice', 'customer');
        });
    }

    /**
     * Complete/confirm a payment.
     */
    public function complete(PaymentReceived $payment): PaymentReceived
    {
        if ($payment->status !== PaymentReceived::STATUS_PENDING) {
            throw new \InvalidArgumentException('Only pending payments can be completed.');
        }

        return DB::transaction(function () use ($payment) {
            // Create journal entry
            $journal = $this->createJournalEntry($payment);

            $payment->update([
                'status' => PaymentReceived::STATUS_COMPLETED,
                'journal_entry_id' => $journal->id,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Void a payment.
     */
    public function void(PaymentReceived $payment, string $reason = ''): PaymentReceived
    {
        if ($payment->status === PaymentReceived::STATUS_VOIDED) {
            throw new \InvalidArgumentException('Payment is already voided.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            // Reverse allocations
            foreach ($payment->allocations as $allocation) {
                $invoice = $allocation->invoice;
                $invoice->amount_paid = bcsub((string) $invoice->amount_paid, (string) $allocation->amount, 4);
                $invoice->amount_due = bcadd((string) $invoice->amount_due, (string) $allocation->amount, 4);

                // Reset invoice status
                if (bccomp((string) $invoice->amount_paid, '0', 4) <= 0) {
                    $invoice->status = $invoice->isOverdue() ? Invoice::STATUS_OVERDUE : Invoice::STATUS_SENT;
                } else {
                    $invoice->status = Invoice::STATUS_PARTIAL;
                }

                $invoice->save();
            }

            // Delete allocations
            $payment->allocations()->delete();

            // Void customer credit if created
            CustomerCredit::where('source_type', CustomerCredit::SOURCE_OVERPAYMENT)
                ->where('source_id', $payment->id)
                ->update(['is_active' => false, 'remaining_amount' => 0]);

            // Reverse journal entry
            if ($payment->journal_entry_id) {
                $this->journalService->void($payment->journalEntry, $reason);
            }

            $payment->update([
                'status' => PaymentReceived::STATUS_VOIDED,
                'notes' => $payment->notes . "\n\nVoided: " . $reason,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Record a bounced cheque.
     */
    public function recordBounce(PaymentReceived $payment, string $reason = ''): PaymentReceived
    {
        if ($payment->payment_method !== PaymentReceived::METHOD_CHEQUE) {
            throw new \InvalidArgumentException('Only cheque payments can bounce.');
        }

        if ($payment->status !== PaymentReceived::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Only completed payments can bounce.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            // Reverse same as void but with different status
            foreach ($payment->allocations as $allocation) {
                $invoice = $allocation->invoice;
                $invoice->amount_paid = bcsub((string) $invoice->amount_paid, (string) $allocation->amount, 4);
                $invoice->amount_due = bcadd((string) $invoice->amount_due, (string) $allocation->amount, 4);

                if (bccomp((string) $invoice->amount_paid, '0', 4) <= 0) {
                    $invoice->status = $invoice->isOverdue() ? Invoice::STATUS_OVERDUE : Invoice::STATUS_SENT;
                } else {
                    $invoice->status = Invoice::STATUS_PARTIAL;
                }

                $invoice->save();
            }

            // Create reversal journal entry
            if ($payment->journal_entry_id) {
                $this->journalService->reverse($payment->journalEntry, "Cheque bounced: {$reason}");
            }

            $payment->update([
                'status' => PaymentReceived::STATUS_BOUNCED,
                'notes' => $payment->notes . "\n\nBounced: " . $reason,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Allocate payment to an invoice.
     */
    public function allocate(PaymentReceived $payment, Invoice $invoice, float $amount): PaymentAllocation
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Allocation amount must be positive.');
        }

        $available = $payment->getUnallocatedAmount();
        if ($amount > $available) {
            throw new \InvalidArgumentException("Cannot allocate {$amount}. Only {$available} available.");
        }

        if ($amount > $invoice->amount_due) {
            throw new \InvalidArgumentException("Cannot allocate {$amount}. Invoice only has {$invoice->amount_due} due.");
        }

        // Check currency match
        if ($payment->currency_code !== $invoice->currency_code) {
            throw new \InvalidArgumentException('Payment and invoice currencies must match.');
        }

        $allocation = PaymentAllocation::create([
            'payment_received_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'base_amount' => bcmul((string) $amount, (string) $payment->exchange_rate, 4),
            'allocated_at' => now(),
        ]);

        // Update invoice
        $invoice->recordPayment($amount);

        return $allocation;
    }

    /**
     * Deallocate payment from an invoice.
     */
    public function deallocate(PaymentAllocation $allocation): void
    {
        if ($allocation->payment->status !== PaymentReceived::STATUS_PENDING) {
            throw new \InvalidArgumentException('Can only modify pending payment allocations.');
        }

        DB::transaction(function () use ($allocation) {
            $invoice = $allocation->invoice;

            // Reverse payment on invoice
            $invoice->amount_paid = bcsub((string) $invoice->amount_paid, (string) $allocation->amount, 4);
            $invoice->amount_due = bcadd((string) $invoice->amount_due, (string) $allocation->amount, 4);

            if (bccomp((string) $invoice->amount_paid, '0', 4) <= 0) {
                $invoice->status = Invoice::STATUS_SENT;
            } elseif (bccomp((string) $invoice->amount_due, '0', 4) > 0) {
                $invoice->status = Invoice::STATUS_PARTIAL;
            }

            $invoice->save();

            $allocation->delete();
        });
    }

    /**
     * Apply customer credit to an invoice.
     */
    public function applyCredit(int $customerId, Invoice $invoice, ?float $amount = null): float
    {
        $credits = CustomerCredit::forCustomer($customerId)
            ->active()
            ->where('currency_code', $invoice->currency_code)
            ->orderBy('credit_date')
            ->get();

        $totalApplied = 0;

        foreach ($credits as $credit) {
            if ($invoice->amount_due <= 0) {
                break;
            }

            $toApply = $amount !== null
                ? min($amount - $totalApplied, (float) $credit->remaining_amount, (float) $invoice->amount_due)
                : min((float) $credit->remaining_amount, (float) $invoice->amount_due);

            if ($toApply > 0) {
                $applied = $credit->applyToInvoice($invoice, $toApply);
                $totalApplied = bcadd((string) $totalApplied, (string) $applied, 4);
            }

            if ($amount !== null && bccomp((string) $totalApplied, (string) $amount, 4) >= 0) {
                break;
            }
        }

        return (float) $totalApplied;
    }

    /**
     * Create customer credit.
     */
    protected function createCustomerCredit(PaymentReceived $payment, float $amount): CustomerCredit
    {
        return CustomerCredit::create([
            'organization_id' => $payment->organization_id,
            'customer_id' => $payment->customer_id,
            'source_type' => CustomerCredit::SOURCE_OVERPAYMENT,
            'source_id' => $payment->id,
            'original_amount' => $amount,
            'remaining_amount' => $amount,
            'currency_code' => $payment->currency_code,
            'credit_date' => $payment->payment_date,
            'notes' => "Overpayment from payment {$payment->payment_number}",
        ]);
    }

    /**
     * Create journal entry for payment.
     */
    protected function createJournalEntry(PaymentReceived $payment): \App\Models\Accounting\JournalEntry
    {
        $customer = $payment->customer;
        $bankAccountId = $payment->bank_account_id ?? config('erp.default_accounts.cash');
        $receivableAccountId = $customer->receivable_account_id ?? config('erp.default_accounts.receivable');

        $lines = [
            // Debit: Bank/Cash
            [
                'account_id' => $bankAccountId,
                'description' => "Payment {$payment->payment_number} from {$customer->getDisplayName()}",
                'debit' => $payment->amount,
                'credit' => 0,
            ],
            // Credit: Accounts Receivable
            [
                'account_id' => $receivableAccountId,
                'description' => "Payment {$payment->payment_number}",
                'debit' => 0,
                'credit' => $payment->amount,
                'contact_id' => $customer->id,
            ],
        ];

        return $this->journalService->create([
            'entry_date' => $payment->payment_date,
            'reference' => $payment->payment_number,
            'description' => "Payment Received - {$customer->getDisplayName()}",
            'source_type' => PaymentReceived::class,
            'source_id' => $payment->id,
            'branch_id' => $payment->branch_id,
        ], $lines);
    }

    /**
     * Get customer statement.
     */
    public function getCustomerStatement(
        int $customerId,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null
    ): array {
        $startDate = $startDate ?? now()->startOfYear();
        $endDate = $endDate ?? now();

        // Get opening balance
        $openingBalance = Invoice::forCustomer($customerId)
            ->where('invoice_date', '<', $startDate)
            ->sum('amount_due');

        // Get invoices in period
        $invoices = Invoice::forCustomer($customerId)
            ->inDateRange($startDate, $endDate)
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_VOIDED])
            ->orderBy('invoice_date')
            ->get();

        // Get payments in period
        $payments = PaymentReceived::forCustomer($customerId)
            ->inDateRange($startDate, $endDate)
            ->whereIn('status', [PaymentReceived::STATUS_COMPLETED])
            ->orderBy('payment_date')
            ->get();

        // Build statement lines
        $lines = [];
        $runningBalance = (float) $openingBalance;

        $allTransactions = collect()
            ->merge($invoices->map(fn($i) => ['type' => 'invoice', 'date' => $i->invoice_date, 'data' => $i]))
            ->merge($payments->map(fn($p) => ['type' => 'payment', 'date' => $p->payment_date, 'data' => $p]))
            ->sortBy('date');

        foreach ($allTransactions as $transaction) {
            if ($transaction['type'] === 'invoice') {
                $invoice = $transaction['data'];
                $runningBalance = bcadd((string) $runningBalance, (string) $invoice->total, 4);

                $lines[] = [
                    'date' => $invoice->invoice_date->toDateString(),
                    'type' => 'invoice',
                    'number' => $invoice->invoice_number,
                    'description' => "Invoice",
                    'debit' => $invoice->total,
                    'credit' => 0,
                    'balance' => $runningBalance,
                ];
            } else {
                $payment = $transaction['data'];
                $runningBalance = bcsub((string) $runningBalance, (string) $payment->amount, 4);

                $lines[] = [
                    'date' => $payment->payment_date->toDateString(),
                    'type' => 'payment',
                    'number' => $payment->payment_number,
                    'description' => "Payment - {$payment->getPaymentMethodLabel()}",
                    'debit' => 0,
                    'credit' => $payment->amount,
                    'balance' => $runningBalance,
                ];
            }
        }

        return [
            'customer_id' => $customerId,
            'period_start' => $startDate->format('Y-m-d'),
            'period_end' => $endDate->format('Y-m-d'),
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'total_invoiced' => $invoices->sum('total'),
            'total_paid' => $payments->sum('amount'),
            'lines' => $lines,
        ];
    }
}
