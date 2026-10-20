<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Writing off what a customer still owes on an invoice: money the platform
 * gives up on, so a second admin can be asked first.
 */
final class FinanceActions implements ApprovableActions
{
    public const WRITE_OFF = 'finance.write_off';

    public function __construct(
        private readonly Receivables $receivables,
    ) {}

    public function keys(): array
    {
        return [self::WRITE_OFF];
    }

    public function label(string $action): string
    {
        return __('Write off what a customer still owes on an invoice');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::BillingManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $receivable = $this->receivables->find((string) $payload['organization_id'], (string) $payload['receivable_id'])
            ?? throw new DomainException(__('There is no such invoice.'));

        if ((float) $receivable->balance() <= 0) {
            throw new DomainException(__('Nothing is left to write off on invoice :number.', ['number' => $this->receivables->number($receivable)]));
        }

        return new Preview(
            __('Write off :amount on invoice :number to :customer: :reason', [
                'amount' => $receivable->balance().' '.$receivable->currency,
                'number' => $this->receivables->number($receivable),
                'customer' => (string) $receivable->organization?->name,
                'reason' => (string) $payload['reason'],
            ]),
            'Receivable', (string) $receivable->id,
            ['status' => $receivable->status->value, 'balance' => $receivable->balance()],
            ['status' => 'written_off', 'written_off' => $receivable->balance(), 'reason' => $payload['reason']],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $receivable = $this->receivables->find((string) $payload['organization_id'], (string) $payload['receivable_id'])
            ?? throw new DomainException(__('There is no such invoice.'));

        $written = $this->receivables->writeOff($receivable, (string) $payload['reason'], (string) $requester->id);

        return new ActionResult(__('Written off: :amount on invoice :number.', [
            'amount' => $written->amount_written_off.' '.$written->currency,
            'number' => $this->receivables->number($written),
        ]));
    }
}
