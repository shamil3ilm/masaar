<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\DTOs\CardCheckout;
use App\Domains\Billing\DTOs\CheckoutSession;
use App\Domains\Billing\DTOs\ReceivedPayment;
use App\Domains\Billing\Enums\PaymentChannel;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Money a customer sends against its invoices: a bank transfer an admin
 * records when it arrives, or a card payment through the card gateway.
 *
 * Each is set against one or more invoices (allocations) - those named, or
 * the oldest open ones in its currency - and what is left over becomes the
 * customer's credit, taken by its next invoice. Short of what an invoice
 * owes, it leaves the invoice part paid. What the bank or the gateway kept
 * is recorded as the payment's fee, the customer credited in full; for a
 * payment in another currency, the difference against each invoice's own
 * exchange rate is kept on the allocation.
 *
 * Every payment carries its channel and reference, which is what the
 * reconciliation stage matches bank statement lines and the gateway's
 * settlement reports against.
 */
class Payments
{
    /** How long a started card payment is waited for. */
    private const CARD_WAIT_SECONDS = 86400;

    public function __construct(
        private readonly Receivables $receivables,
        private readonly Credits $credits,
        private readonly FinanceSettings $settings,
        private readonly PaymentGateway $gateway,
        private readonly PlatformInvoices $platform,
        private readonly AuditService $audit,
        private readonly Repository $cache,
    ) {}

    /**
     * A bank transfer that arrived.
     *
     * @throws DomainException saying what is wrong with it
     */
    public function recordTransfer(Organization $customer, ReceivedPayment $received, ?string $actorId): Payment
    {
        if (! $this->settings->methodEnabled(PaymentMethod::BankTransfer)) {
            throw new DomainException(__('Bank transfers are switched off in Finance › Settings.'));
        }

        $currency = $this->currency($received->currency);
        $amount = $this->amount($received->amount);
        $fee = $received->fee === null || $received->fee === '' ? null : $this->amount($received->fee, zero: true);

        if ($fee !== null && (float) $fee > (float) $amount) {
            throw new DomainException(__('The bank fee is more than the payment.'));
        }

        $accounts = collect($this->settings->bankAccounts($currency));

        if ($received->bankAccount !== null && $received->bankAccount !== '' && ! $accounts->contains('key', $received->bankAccount)) {
            throw new DomainException(__('That is not one of the platform\'s :currency bank accounts.', ['currency' => $currency]));
        }

        return DB::transaction(function () use ($customer, $received, $currency, $amount, $fee, $actorId): Payment {
            $payment = $this->create($customer, [
                'amount' => $amount,
                'currency' => $currency,
                'method' => PaymentMethod::BankTransfer,
                'channel' => PaymentChannel::Bank,
                'reference' => $received->reference,
                'bank_account' => $received->bankAccount ?: null,
                'fee_amount' => $fee,
                'exchange_rate' => $this->rate($currency, $received->exchangeRate),
                'paid_at' => $received->receivedOn->toDateString(),
                'notes' => $received->notes,
            ], $actorId);

            $this->allocate($payment, $received->allocations, $actorId);

            return $payment->fresh() ?? $payment;
        });
    }

    /**
     * A card payment the gateway's settlement report shows and the platform
     * has no record of - the customer paid and never came back from the
     * gateway's page (finishCard). Recorded as finishCard would have, once
     * per gateway transaction, with the fee the gateway kept.
     *
     * @throws DomainException saying what is wrong with it, or when that transaction is already recorded
     */
    public function recordSettledCard(Organization $customer, ReceivedPayment $received, string $gatewayReference, ?string $actorId): Payment
    {
        $currency = $this->currency($received->currency);
        $amount = $this->amount($received->amount);
        $fee = $received->fee === null || $received->fee === '' ? null : $this->amount($received->fee, zero: true);

        if ($this->byGatewayReference($gatewayReference) !== null) {
            throw new DomainException(__('Card transaction :reference is already recorded.', ['reference' => $gatewayReference]));
        }

        return DB::transaction(function () use ($customer, $received, $currency, $amount, $fee, $gatewayReference, $actorId): Payment {
            $payment = $this->create($customer, [
                'amount' => $amount,
                'currency' => $currency,
                'method' => PaymentMethod::Card,
                'channel' => PaymentChannel::Gateway,
                'reference' => $received->reference,
                'gateway_reference' => $gatewayReference,
                'fee_amount' => $fee,
                'exchange_rate' => $this->rate($currency, $received->exchangeRate),
                'paid_at' => $received->receivedOn->toDateString(),
                'notes' => $received->notes,
            ], $actorId);

            $this->allocate($payment, $received->allocations, $actorId);

            return $payment->fresh() ?? $payment;
        });
    }

    /**
     * Undo a payment recorded in error - a reconciliation match taken back.
     * Nothing is deleted or edited: each allocation is answered by its
     * reversal (the invoices owe it again), what it left to the customer's
     * credit is reversed, and the payment says when, by whom and why.
     *
     * @throws DomainException when it is already reversed, or the credit it left has been used
     */
    public function reverse(Payment $payment, string $reason, ?string $actorId): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actorId): Payment {
            $locked = Payment::withoutTenantScope(fn () => Payment::query()->lockForUpdate()->findOrFail($payment->id));

            if ($locked->reversed_at !== null) {
                throw new DomainException(__('That payment is already reversed.'));
            }

            $credits = BillingCredit::withoutTenantScope(fn () => BillingCredit::query()
                ->where('source', BillingCredit::OVERPAYMENT)->where('source_id', $locked->id)->get());

            foreach ($credits as $credit) {
                $this->credits->reverse($credit, $reason, $actorId);
            }

            $allocations = $locked->allocations()->withoutGlobalScopes()->where('amount', '>', 0)->get();
            $reversed = $locked->allocations()->withoutGlobalScopes()->whereNotNull('reverses_id')->pluck('reverses_id')->all();

            foreach ($allocations as $allocation) {
                if (! in_array($allocation->id, $reversed, true)) {
                    $this->receivables->unsettle($allocation, $actorId);
                }
            }

            $locked->forceFill(['reversed_at' => now(), 'reversed_by' => $actorId, 'reversal_reason' => mb_substr($reason, 0, 500)])->save();

            $this->audit->logFor((string) $locked->org_id, 'finance.payment.reversed', $locked, [
                'amount' => (string) $locked->total, 'currency' => (string) $locked->currency, 'reference' => $locked->reference, 'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /** The card payment the gateway knows by this transaction id, if recorded. */
    public function byGatewayReference(string $gatewayReference): ?Payment
    {
        return Payment::withoutTenantScope(fn () => Payment::query()
            ->where('channel', PaymentChannel::Gateway->value)
            ->where('gateway_reference', $gatewayReference)
            ->first());
    }

    /**
     * Set a payment against invoices: those named with how much, or else
     * the customer's oldest open invoices in its currency. What is left
     * becomes the customer's credit.
     *
     * @param  array<string, string>  $allocations  receivable id => amount
     *
     * @throws DomainException for an invoice that is not the customer's, not in the payment's currency, or owes less
     */
    public function allocate(Payment $payment, array $allocations, ?string $actorId): void
    {
        $left = (float) $payment->total - (float) $payment->allocations()->withoutGlobalScopes()->sum('amount');
        $on = CarbonImmutable::parse($payment->paid_at->toDateString());
        $plan = [];

        if (array_filter($allocations, fn ($amount): bool => $amount !== null && $amount !== '') === []) {
            foreach ($this->receivables->outstanding((string) $payment->org_id, (string) $payment->currency) as $receivable) {
                if ($left <= 0) {
                    break;
                }

                $take = min($left, (float) $receivable->balance());
                $plan[] = [$receivable, Receivable::money($take)];
                $left -= $take;
            }
        } else {
            foreach ($allocations as $receivableId => $amount) {
                if ($amount === null || $amount === '' || (float) $amount === 0.0) {
                    continue;
                }

                $receivable = $this->receivables->find((string) $payment->org_id, (string) $receivableId)
                    ?? throw new DomainException(__('That invoice is not this customer\'s.'));

                if ($receivable->currency !== $payment->currency) {
                    throw new DomainException(__('A payment in :paid is not set against an invoice in :owed.', ['paid' => $payment->currency, 'owed' => $receivable->currency]));
                }

                $plan[] = [$receivable, $this->amount((string) $amount)];
                $left -= (float) $amount;
            }

            if (round($left, 2) < 0) {
                throw new DomainException(__('The amounts set against invoices come to more than the payment.'));
            }
        }

        foreach ($plan as [$receivable, $amount]) {
            $this->receivables->settle($receivable, $amount, $payment, null, $this->fxDifference($payment, $receivable, $amount), $on, $actorId);
        }

        if (round($left, 2) > 0) {
            $this->credits->grant((string) $payment->org_id, (string) $payment->currency, Receivable::money($left), BillingCredit::OVERPAYMENT,
                (string) $payment->id, __('More than the invoices it settled: payment :reference', ['reference' => (string) $payment->reference]), grantedOn: $on);
        }
    }

    /**
     * Send the customer to the card gateway for what an invoice still owes.
     *
     * @throws DomainException when card payments are off, or nothing is owed
     */
    public function startCard(Receivable $receivable, string $returnUrl): CheckoutSession
    {
        if (! $this->cardsAccepted()) {
            throw new DomainException(__('Card payments are not available.'));
        }

        if (! $receivable->status->outstanding() || (float) $receivable->balance() <= 0) {
            throw new DomainException(__('Nothing is owed on this invoice.'));
        }

        $number = $this->receivables->number($receivable);
        $session = $this->gateway->checkout(new CardCheckout(
            amount: $receivable->balance(),
            currency: (string) $receivable->currency,
            reference: $number,
            description: __('Invoice :number', ['number' => $number]),
            returnUrl: $returnUrl,
        ));

        $this->cache->put($this->cardKey($session->id), ['receivable_id' => (string) $receivable->id, 'org_id' => (string) $receivable->org_id], self::CARD_WAIT_SECONDS);

        return $session;
    }

    /**
     * Come back from the card gateway: ask it how the payment ended and,
     * when it was taken, record it against the invoice it was for - once,
     * however often the customer comes back.
     *
     * @throws DomainException when it was declined, is not decided yet, or is not this customer's
     */
    public function finishCard(string $sessionId, string $organizationId): Payment
    {
        $pending = $this->cache->get($this->cardKey($sessionId));

        if (! is_array($pending) || $pending['org_id'] !== $organizationId) {
            throw new DomainException(__('There is no such card payment.'));
        }

        return $this->cache->lock($this->cardKey($sessionId).'.lock', 30)->block(10, function () use ($sessionId, $pending): Payment {
            $charge = $this->gateway->charge($sessionId);

            if (! $charge->succeeded()) {
                throw new DomainException($charge->status === 'pending'
                    ? __('The card payment is not decided yet. Try again in a moment.')
                    : __('The card payment did not go through: :reason', ['reason' => (string) $charge->failure]));
            }

            $known = $this->byGatewayReference((string) $charge->transactionId);

            if ($known !== null) {
                return $known;
            }

            $receivable = $this->receivables->find($pending['org_id'], $pending['receivable_id'])
                ?? throw new DomainException(__('There is no such card payment.'));
            $customer = $receivable->organization ?? throw new DomainException(__('There is no such card payment.'));

            return DB::transaction(function () use ($customer, $receivable, $charge): Payment {
                $payment = $this->create($customer, [
                    'amount' => $charge->amount,
                    'currency' => strtoupper($charge->currency),
                    'method' => PaymentMethod::Card,
                    'channel' => PaymentChannel::Gateway,
                    'reference' => $this->receivables->number($receivable),
                    'gateway_reference' => $charge->transactionId,
                    'fee_amount' => $charge->fee,
                    'paid_at' => CarbonImmutable::now()->toDateString(),
                ], null);

                // The invoice it was for, up to what it owes; more is credit.
                $this->allocate($payment, [(string) $receivable->id => Receivable::money(min((float) $charge->amount, (float) $receivable->fresh()?->balance()))], null);

                return $payment->fresh() ?? $payment;
            });
        });
    }

    /** Whether a customer can pay by card now: switched on, and a gateway there to take it. */
    public function cardsAccepted(): bool
    {
        return $this->settings->methodEnabled(PaymentMethod::Card) && $this->gateway->available();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function create(Organization $customer, array $fields, ?string $actorId): Payment
    {
        $payment = new Payment([
            ...$fields,
            'org_id' => $customer->id,
            'vat_amount' => '0.00',
            'total' => $fields['amount'],
        ]);

        if ($actorId !== null) {
            $payment->forceFill(['recorded_by' => $actorId]);
        }

        Payment::withoutTenantScope(fn () => $payment->save());

        $this->audit->logFor((string) $customer->id, 'finance.payment.recorded', $payment, [
            'amount' => (string) $payment->amount,
            'currency' => (string) $payment->currency,
            'method' => $payment->method->value,
            'channel' => $payment->channel?->value,
            'reference' => $payment->reference,
            'fee' => $payment->fee_amount === null ? null : (string) $payment->fee_amount,
        ]);

        return $payment;
    }

    /**
     * The exchange difference in the tax currency on the part of a payment
     * set against one invoice: at the payment's rate, less at the
     * invoice's. Null where either has no rate.
     */
    private function fxDifference(Payment $payment, Receivable $receivable, string $amount): ?string
    {
        if ($payment->exchange_rate === null || $receivable->exchange_rate === null) {
            return null;
        }

        return Receivable::money((float) $amount * ((float) $payment->exchange_rate - (float) $receivable->exchange_rate));
    }

    /** @throws DomainException */
    private function currency(string $currency): string
    {
        $currency = strtoupper($currency);

        if (! $this->settings->currencyEnabled($currency)) {
            throw new DomainException(__(':currency is switched off in Finance › Settings.', ['currency' => $currency]));
        }

        return $currency;
    }

    /** @throws DomainException */
    private function amount(string $amount, bool $zero = false): string
    {
        if (! is_numeric($amount) || preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) !== 1 || (! $zero && (float) $amount <= 0)) {
            throw new DomainException(__('An amount is a positive number with at most two decimals.'));
        }

        return Receivable::money((float) $amount);
    }

    /** @throws DomainException */
    private function rate(string $currency, ?string $rate): ?string
    {
        if ($currency === $this->platform->taxCurrency() || $rate === null || $rate === '') {
            return null;
        }

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new DomainException(__('The exchange rate is a positive number.'));
        }

        return number_format((float) $rate, 6, '.', '');
    }

    private function cardKey(string $sessionId): string
    {
        return 'billing.card.'.$sessionId;
    }
}
