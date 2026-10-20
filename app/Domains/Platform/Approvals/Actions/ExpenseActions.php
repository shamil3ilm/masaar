<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\OutgoingPayment;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\SupplierPayments;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Money the platform pays out on its own expenses: a large payment to a
 * supplier (finance.large_payment_threshold or more in the tax currency),
 * and writing off what is still owed on an expense. Either can be held
 * for a second admin. A payment under the threshold is never held.
 */
final class ExpenseActions implements ApprovableActions
{
    public const PAY = 'finance.supplier_payment';

    public const WRITE_OFF = 'finance.expense_write_off';

    public function __construct(
        private readonly Expenses $expenses,
        private readonly SupplierPayments $payments,
    ) {}

    public function keys(): array
    {
        return [self::PAY, self::WRITE_OFF];
    }

    public function label(string $action): string
    {
        return match ($action) {
            self::PAY => __('Pay a supplier a large amount'),
            default => __('Write off what is still owed on an expense'),
        };
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::BillingManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $expense = $this->expenses->find((string) $payload['expense_id']) ?? throw new DomainException(__('There is no such expense.'));
        $supplier = (string) $expense->supplier?->name;

        if ((float) $expense->balance() <= 0) {
            throw new DomainException(__('Nothing is owed on this expense.'));
        }

        if ($action === self::PAY) {
            $payment = OutgoingPayment::fromArray($payload['payment']);

            return new Preview(
                __('Pay :supplier :amount by :channel against its bill of :date', [
                    'supplier' => $supplier, 'amount' => $payment->amount.' '.$expense->currency,
                    'channel' => mb_strtolower($payment->channel->label()), 'date' => $expense->expense_date->toDateString(),
                ]),
                'Expense', (string) $expense->id,
                ['status' => $expense->status->value, 'balance' => $expense->balance()],
                ['paid' => $payment->amount, 'reference' => $payment->reference],
            );
        }

        return new Preview(
            __('Write off :amount owed to :supplier on its bill of :date: :reason', [
                'amount' => $expense->balance().' '.$expense->currency, 'supplier' => $supplier,
                'date' => $expense->expense_date->toDateString(), 'reason' => (string) $payload['reason'],
            ]),
            'Expense', (string) $expense->id,
            ['status' => $expense->status->value, 'balance' => $expense->balance()],
            ['status' => 'written_off', 'written_off' => $expense->balance(), 'reason' => $payload['reason']],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $expense = $this->expenses->find((string) $payload['expense_id']) ?? throw new DomainException(__('There is no such expense.'));

        if ($action === self::PAY) {
            $paid = $this->payments->pay($expense, OutgoingPayment::fromArray($payload['payment']), (string) $requester->id);

            return new ActionResult(__('Payment of :amount recorded.', ['amount' => $paid->amount.' '.$paid->currency]));
        }

        $written = $this->payments->writeOff($expense, (string) $payload['reason'], (string) $requester->id);

        return new ActionResult(__('Written off: :amount.', ['amount' => $written->amount_written_off.' '.$written->currency]));
    }
}
