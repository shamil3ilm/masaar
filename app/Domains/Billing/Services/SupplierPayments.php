<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\DTOs\OutgoingPayment;
use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\SupplierPayment;
use App\Domains\Billing\Settings\FinanceSettings;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Paying suppliers: money against an expense, in full or in parts, and
 * what the platform will not pay written off.
 *
 * A payment of finance.large_payment_threshold or more in the tax currency
 * is large, and an approval policy can hold it for a second admin
 * (ExpenseActions), as it can every write-off. Each payment keeps how it
 * went out and the reference its statement shows - what a reconciliation
 * of outgoing payments matches against.
 */
class SupplierPayments
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly AuditService $audit,
    ) {}

    /** Whether a payment of this much on this expense is a large one. */
    public function large(Expense $expense, string $amount): bool
    {
        return $this->inTaxCurrency($expense, (float) $amount) >= (float) $this->settings->largePaymentThreshold();
    }

    /**
     * Record money paid against the expense: what is left owed goes down,
     * and the expense is paid once nothing is.
     *
     * @throws DomainException
     */
    public function pay(Expense $expense, OutgoingPayment $payment, ?string $actorId): SupplierPayment
    {
        return DB::transaction(function () use ($expense, $payment, $actorId): SupplierPayment {
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->sole();
            $amount = round((float) $payment->amount, 2);

            $this->owed($expense);

            if ($amount <= 0 || $amount > (float) $expense->balance() + 0.005) {
                throw new DomainException(__('A payment is more than zero and at most what is owed: :balance.', ['balance' => $expense->balance().' '.$expense->currency]));
            }

            if ($payment->paidOn->isAfter(now()->endOfDay())) {
                throw new DomainException(__('A payment is recorded once it is made, not before.'));
            }

            $paid = new SupplierPayment([
                'expense_id' => $expense->id,
                'supplier_id' => $expense->supplier_id,
                'paid_on' => $payment->paidOn->toDateString(),
                'currency' => $expense->currency,
                'amount' => Receivable::money($amount),
                'amount_tax' => Receivable::money($this->inTaxCurrency($expense, $amount)),
                'channel' => $payment->channel,
                'reference' => $payment->reference,
                'paid_from' => $payment->paidFrom,
                'notes' => $payment->notes,
            ]);
            // Who asked for it, also when an approver's click ran it.
            $paid->forceFill(['created_by' => $actorId])->save();

            $expense->amount_paid = Receivable::money((float) $expense->amount_paid + $amount);
            $settled = (float) $expense->balance() <= 0.0;
            $expense->status = $settled ? ExpenseStatus::Paid : ExpenseStatus::PartlyPaid;
            $expense->paid_on = $settled ? $payment->paidOn->toDateString() : null;
            $expense->save();

            $this->audit->logChange('finance.supplier_payment.recorded', 'SupplierPayment', (string) $paid->id, null, [
                'expense_id' => (string) $expense->id, 'amount' => (string) $paid->amount, 'currency' => $paid->currency,
                'channel' => $paid->channel->value, 'reference' => $paid->reference,
            ], [], $actorId);

            return $paid;
        });
    }

    /**
     * What is left owed on the expense, given up: the supplier waived it, or
     * it will never be paid.
     *
     * @throws DomainException
     */
    public function writeOff(Expense $expense, string $reason, ?string $actorId): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actorId): Expense {
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->sole();
            $this->owed($expense);
            $balance = $expense->balance();

            $expense->amount_written_off = Receivable::money((float) $expense->amount_written_off + (float) $balance);
            $expense->status = ExpenseStatus::WrittenOff;
            $expense->forceFill(['written_off_at' => now(), 'written_off_by' => $actorId, 'write_off_reason' => $reason])->save();

            $this->audit->logChange('finance.expense.written_off', 'Expense', (string) $expense->id,
                ['balance' => $balance], ['written_off' => $balance, 'reason' => $reason], [], $actorId);

            return $expense;
        });
    }

    /**
     * @throws DomainException
     */
    private function owed(Expense $expense): void
    {
        if (! in_array($expense->status->value, ExpenseStatus::owedValues(), true)) {
            throw new DomainException(__('Nothing is owed on this expense: it is :status.', ['status' => mb_strtolower($expense->status->label())]));
        }
    }

    private function inTaxCurrency(Expense $expense, float $amount): float
    {
        return round($amount * ($expense->exchange_rate === null ? 1.0 : (float) $expense->exchange_rate), 2);
    }
}
