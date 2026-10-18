<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Services\Billing;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Recording a payment: the amount agreed, the plan it pays for and how long
 * it runs - which renews the plan and invoices it.
 */
final class BillingActions implements ApprovableActions
{
    public function __construct(
        private readonly Billing $billing,
    ) {}

    public function keys(): array
    {
        return ['billing.record'];
    }

    public function label(string $action): string
    {
        return __('Record a payment, changing the plan, its amount or its term');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::BillingManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $organization = $this->billing->organization((string) $payload['organization_id'])
            ?? throw new DomainException(__('There is no such organization.'));
        $licence = $this->billing->licenceOf((string) $organization->id);

        return new Preview(
            __('Record :amount for :months months of the :tier plan for :organization', [
                'amount' => $payload['amount'], 'months' => $payload['months'], 'tier' => $payload['tier'], 'organization' => $organization->name,
            ]),
            'Organization', (string) $organization->id,
            ['tier' => $licence?->tier?->value, 'expires_at' => $licence?->expires_at?->toDateString()],
            ['tier' => $payload['tier'], 'months' => $payload['months'], 'amount' => $payload['amount'], 'method' => $payload['method'], 'paid_at' => $payload['paid_at']],
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $organization = $this->billing->organization((string) $payload['organization_id'])
            ?? throw new DomainException(__('There is no such organization.'));

        $payment = $this->billing->record(
            $organization,
            LicenseTier::from((string) $payload['tier']),
            (int) $payload['months'],
            (string) $payload['amount'],
            PaymentMethod::from((string) $payload['method']),
            $payload['reference'] ?? null,
            CarbonImmutable::parse((string) $payload['paid_at']),
            $payload['notes'] ?? null,
            (string) $requester->id,
        );

        $message = 'Payment recorded. The plan now runs to '.$payment->period_end->toDateString().'.';

        if ($this->billing->invoicedLate(CarbonImmutable::parse((string) $payload['paid_at']))) {
            $message .= ' This payment is past the fifteen days after its month in which ZATCA expects it invoiced.';
        }

        return $payment->invoice_id === null
            ? new ActionResult($message.' No invoice was issued: '.$payment->invoice_error, ['tone' => 'error'])
            : new ActionResult($message.' Invoice issued.');
    }
}
