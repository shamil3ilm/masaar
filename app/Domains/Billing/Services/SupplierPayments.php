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
 *
 * A payment in another currency is in riyals at its own day's rate (the
 * rate table's, or one entered), and realises an exchange difference on
 * the part it settles: what that part was booked at on the expense less
 * what was paid for it, a gain when positive. Part payments settle pro
 * rata; the one that pays the bill off takes what is left.
 */
class SupplierPayments
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly ExchangeRates $rates,
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

            [$rate, $source, $rateDate] = $this->rate($expense, $payment);
            $paidTax = round($amount * ($rate === null ? 1.0 : (float) $rate), 2);
            $settledTax = $this->settled($expense, $amount);

            $paid = new SupplierPayment([
                'expense_id' => $expense->id,
                'supplier_id' => $expense->supplier_id,
                'paid_on' => $payment->paidOn->toDateString(),
                'currency' => $expense->currency,
                'amount' => Receivable::money($amount),
                'amount_tax' => Receivable::money($paidTax),
                'exchange_rate' => $rate,
                'rate_source' => $source,
                'rate_date' => $rateDate,
                'settled_tax' => Receivable::money($settledTax),
                'exchange_difference' => Receivable::money($rate === null ? 0.0 : $settledTax - $paidTax),
                'channel' => $payment->channel,
                'reference' => $payment->reference,
                'paid_from' => $payment->paidFrom,
                'notes' => $payment->notes,
            ]);
            // Who asked for it, also when an approver's click ran it.
            $paid->forceFill(['created_by' => $actorId])->save();

            $expense->exchange_difference = Receivable::money((float) $expense->exchange_difference + (float) $paid->exchange_difference);
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

    /**
     * The payment's rate to the tax currency: none in the tax currency
     * itself, the one typed, or the rate table's on the payment date.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     *
     * @throws DomainException
     */
    private function rate(Expense $expense, OutgoingPayment $payment): array
    {
        if ($expense->currency === $expense->tax_currency) {
            return [null, null, null];
        }

        if ($payment->exchangeRate !== null) {
            if (! is_numeric($payment->exchangeRate) || (float) $payment->exchangeRate <= 0) {
                throw new DomainException(__('The rate is a positive number.'));
            }

            return [number_format((float) $payment->exchangeRate, 6, '.', ''), FinanceSettings::RATE_MANUAL, $payment->paidOn->toDateString()];
        }

        $table = $this->rates->on($expense->currency, $payment->paidOn)
            ?? throw new DomainException(__('The rate table has no :currency rate on or before :date. Enter the rate, or add it to the table in Finance › Settings.', [
                'currency' => $expense->currency, 'date' => $payment->paidOn->toDateString(),
            ]));

        return [$table['rate'], FinanceSettings::RATE_TABLE, $table['date']];
    }

    /**
     * What the part a payment settles was booked at, in the tax currency:
     * its share of the expense's amount, the payment that pays it off
     * taking what is left so the shares add up to the whole.
     */
    private function settled(Expense $expense, float $amount): float
    {
        if ((float) $expense->gross <= 0) {
            return 0.0;
        }

        if ($amount >= (float) $expense->balance() - 0.005 && (float) $expense->amount_written_off <= 0) {
            return round((float) $expense->gross_tax - (float) $expense->payments()->sum('settled_tax'), 2);
        }

        return round($amount / (float) $expense->gross * (float) $expense->gross_tax, 2);
    }

    private function inTaxCurrency(Expense $expense, float $amount): float
    {
        return round($amount * ($expense->exchange_rate === null ? 1.0 : (float) $expense->exchange_rate), 2);
    }
}
