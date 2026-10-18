<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Organization\Models\Organization;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\NumberRange;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use App\Support\CountryClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What an organization has paid for its plan, as a history list.
 *
 * Every query names the organization, as Billing's do.
 */
class PaymentHistory
{
    /** @return array<string, string> */
    public static function plans(): array
    {
        return [
            'starter' => __('Starter'),
            'professional' => __('Professional'),
            'enterprise' => __('Enterprise'),
            'unlimited' => __('Unlimited'),
        ];
    }

    public function spec(Organization $organization, string $key): ListSpec
    {
        return new ListSpec(
            key: $key,
            sorts: [
                'date' => new Sort(__('Received'), 'paid_at'),
                'amount' => new Sort(__('Amount'), 'total'),
            ],
            defaultSort: 'date',
            filters: [
                new DateRange('paid_at', __('Received'), CountryClock::timeZone($organization->country)),
                new NumberRange('total', __('Total')),
                new Choice('method', __('Paid by'), [
                    PaymentMethod::BankTransfer->value => __('Bank transfer'),
                    PaymentMethod::Card->value => __('Card'),
                    PaymentMethod::Cheque->value => __('Cheque'),
                    PaymentMethod::Cash->value => __('Cash'),
                    PaymentMethod::Other->value => __('Other'),
                ], 'method'),
                new Choice('plan', __('Plan'), self::plans(), 'tier'),
            ],
            defaultPerPage: 10,
        );
    }

    public function page(string $organizationId, ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query($organizationId));
    }

    public function csv(string $organizationId, ListState $list, string $fileName): StreamedResponse
    {
        $plans = self::plans();
        $money = fn (mixed $amount): string => number_format((float) $amount, 2, '.', '');

        return CsvExport::download($fileName, [
            __('Received') => fn (Payment $payment): string => (string) $payment->paid_at?->toDateString(),
            __('Plan') => fn (Payment $payment): string => $plans[(string) $payment->tier] ?? (string) $payment->tier,
            __('From') => fn (Payment $payment): string => (string) $payment->period_start?->toDateString(),
            __('To') => fn (Payment $payment): string => (string) $payment->period_end?->toDateString(),
            __('Currency') => fn (Payment $payment): string => (string) $payment->currency,
            __('Net') => fn (Payment $payment): string => $money($payment->amount),
            __('VAT') => fn (Payment $payment): string => $money($payment->vat_amount),
            __('Total') => fn (Payment $payment): string => $money($payment->total),
            __('Reference') => fn (Payment $payment): string => (string) $payment->reference,
        ], $list->apply($this->query($organizationId))->lazy(500));
    }

    /**
     * @return Builder<Payment>
     */
    private function query(string $organizationId): Builder
    {
        return Payment::query()->where('org_id', $organizationId);
    }
}
