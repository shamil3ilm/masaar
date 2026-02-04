<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Models\Purchase\Bill;
use App\Models\Purchase\BillPaymentAllocation;
use App\Models\Purchase\PaymentMade;
use App\Models\Purchase\SupplierCredit;
use App\Services\Accounting\JournalService;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class PaymentMadeService
{
    public function __construct(
        private JournalService $journalService,
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new payment.
     */
    public function create(array $data, array $allocations = []): PaymentMade
    {
        return DB::transaction(function () use ($data, $allocations) {
            if (empty($data['payment_number'])) {
                $data['payment_number'] = $this->numberGenerator->generate('PAYM');
            }

            $data['base_amount'] = bcmul(
                (string) $data['amount'],
                (string) ($data['exchange_rate'] ?? 1),
                4
            );

            $payment = PaymentMade::create($data);

            $totalAllocated = 0;
            foreach ($allocations as $allocation) {
                $bill = Bill::findOrFail($allocation['bill_id']);
                $amount = min($allocation['amount'], (float) $bill->amount_due);

                if ($amount > 0) {
                    $this->allocate($payment, $bill, $amount);
                    $totalAllocated = bcadd((string) $totalAllocated, (string) $amount, 4);
                }
            }

            $unallocated = bcsub((string) $payment->amount, (string) $totalAllocated, 4);
            if (bccomp($unallocated, '0', 4) > 0) {
                $this->createSupplierCredit($payment, (float) $unallocated);
            }

            return $payment->load('allocations.bill', 'supplier');
        });
    }

    /**
     * Complete/confirm a payment.
     */
    public function complete(PaymentMade $payment): PaymentMade
    {
        if ($payment->status !== PaymentMade::STATUS_PENDING) {
            throw new \InvalidArgumentException('Only pending payments can be completed.');
        }

        return DB::transaction(function () use ($payment) {
            $journal = $this->createJournalEntry($payment);

            $payment->update([
                'status' => PaymentMade::STATUS_COMPLETED,
                'journal_entry_id' => $journal->id,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Void a payment.
     */
    public function void(PaymentMade $payment, string $reason = ''): PaymentMade
    {
        if ($payment->status === PaymentMade::STATUS_VOIDED) {
            throw new \InvalidArgumentException('Payment is already voided.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            foreach ($payment->allocations as $allocation) {
                $bill = $allocation->bill;
                $bill->amount_paid = bcsub((string) $bill->amount_paid, (string) $allocation->amount, 4);
                $bill->amount_due = bcadd((string) $bill->amount_due, (string) $allocation->amount, 4);

                if (bccomp((string) $bill->amount_paid, '0', 4) <= 0) {
                    $bill->status = Bill::STATUS_APPROVED;
                } else {
                    $bill->status = Bill::STATUS_PARTIAL;
                }

                $bill->save();
            }

            $payment->allocations()->delete();

            SupplierCredit::where('source_type', SupplierCredit::SOURCE_OVERPAYMENT)
                ->where('source_id', $payment->id)
                ->update(['is_active' => false, 'remaining_amount' => 0]);

            if ($payment->journal_entry_id) {
                $this->journalService->void($payment->journalEntry, $reason);
            }

            $payment->update([
                'status' => PaymentMade::STATUS_VOIDED,
                'notes' => $payment->notes . "\n\nVoided: " . $reason,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Allocate payment to a bill.
     */
    public function allocate(PaymentMade $payment, Bill $bill, float $amount): BillPaymentAllocation
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Allocation amount must be positive.');
        }

        $available = $payment->getUnallocatedAmount();
        if ($amount > $available) {
            throw new \InvalidArgumentException("Cannot allocate {$amount}. Only {$available} available.");
        }

        if ($amount > $bill->amount_due) {
            throw new \InvalidArgumentException("Cannot allocate {$amount}. Bill only has {$bill->amount_due} due.");
        }

        if ($payment->currency_code !== $bill->currency_code) {
            throw new \InvalidArgumentException('Payment and bill currencies must match.');
        }

        $allocation = BillPaymentAllocation::create([
            'payment_made_id' => $payment->id,
            'bill_id' => $bill->id,
            'amount' => $amount,
            'base_amount' => bcmul((string) $amount, (string) $payment->exchange_rate, 4),
            'allocated_at' => now(),
        ]);

        $bill->recordPayment($amount);

        return $allocation;
    }

    /**
     * Create supplier credit.
     */
    protected function createSupplierCredit(PaymentMade $payment, float $amount): SupplierCredit
    {
        return SupplierCredit::create([
            'organization_id' => $payment->organization_id,
            'supplier_id' => $payment->supplier_id,
            'source_type' => SupplierCredit::SOURCE_OVERPAYMENT,
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
    protected function createJournalEntry(PaymentMade $payment): \App\Models\Accounting\JournalEntry
    {
        $supplier = $payment->supplier;
        $bankAccountId = $payment->bank_account_id ?? config('erp.default_accounts.cash');
        $payableAccountId = $supplier->payable_account_id ?? config('erp.default_accounts.payable');

        $lines = [
            [
                'account_id' => $payableAccountId,
                'description' => "Payment {$payment->payment_number} to {$supplier->getDisplayName()}",
                'debit' => $payment->amount,
                'credit' => 0,
                'contact_id' => $supplier->id,
            ],
            [
                'account_id' => $bankAccountId,
                'description' => "Payment {$payment->payment_number}",
                'debit' => 0,
                'credit' => $payment->amount,
            ],
        ];

        return $this->journalService->create([
            'entry_date' => $payment->payment_date,
            'reference' => $payment->payment_number,
            'description' => "Payment Made - {$supplier->getDisplayName()}",
            'source_type' => PaymentMade::class,
            'source_id' => $payment->id,
            'branch_id' => $payment->branch_id,
        ], $lines);
    }

    /**
     * Get supplier statement.
     */
    public function getSupplierStatement(
        int $supplierId,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null
    ): array {
        $startDate = $startDate ?? now()->startOfYear();
        $endDate = $endDate ?? now();

        $openingBalance = Bill::forSupplier($supplierId)
            ->where('bill_date', '<', $startDate)
            ->sum('amount_due');

        $bills = Bill::forSupplier($supplierId)
            ->inDateRange($startDate, $endDate)
            ->whereNotIn('status', [Bill::STATUS_DRAFT, Bill::STATUS_VOIDED])
            ->orderBy('bill_date')
            ->get();

        $payments = PaymentMade::forSupplier($supplierId)
            ->inDateRange($startDate, $endDate)
            ->completed()
            ->orderBy('payment_date')
            ->get();

        $lines = [];
        $runningBalance = (float) $openingBalance;

        $allTransactions = collect()
            ->merge($bills->map(fn($b) => ['type' => 'bill', 'date' => $b->bill_date, 'data' => $b]))
            ->merge($payments->map(fn($p) => ['type' => 'payment', 'date' => $p->payment_date, 'data' => $p]))
            ->sortBy('date');

        foreach ($allTransactions as $transaction) {
            if ($transaction['type'] === 'bill') {
                $bill = $transaction['data'];
                $runningBalance = bcadd((string) $runningBalance, (string) $bill->total, 4);

                $lines[] = [
                    'date' => $bill->bill_date->toDateString(),
                    'type' => 'bill',
                    'number' => $bill->bill_number,
                    'description' => "Bill",
                    'debit' => 0,
                    'credit' => $bill->total,
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
                    'debit' => $payment->amount,
                    'credit' => 0,
                    'balance' => $runningBalance,
                ];
            }
        }

        return [
            'supplier_id' => $supplierId,
            'period_start' => $startDate->format('Y-m-d'),
            'period_end' => $endDate->format('Y-m-d'),
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'total_billed' => $bills->sum('total'),
            'total_paid' => $payments->sum('amount'),
            'lines' => $lines,
        ];
    }
}
