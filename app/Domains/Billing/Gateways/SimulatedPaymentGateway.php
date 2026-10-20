<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\DTOs\CardCharge;
use App\Domains\Billing\DTOs\CardCheckout;
use App\Domains\Billing\DTOs\CardRefund;
use App\Domains\Billing\DTOs\CheckoutSession;
use App\Domains\Billing\Exceptions\PaymentGatewayException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * A card provider played inside this application, so card payments can be
 * taken end to end with no provider contracted (billing.gateway =
 * simulator).
 *
 * Its "provider page" is a page of this application (routes registered by
 * BillingServiceProvider) where the payment is approved or declined by
 * hand; decide() does the same for a test. A charge it approves keeps the
 * fee billing.simulator.fee_percent says, as a provider would. Refunds of
 * its own charges always go through.
 *
 * Never in production: BillingServiceProvider refuses to boot, and to build
 * this, under APP_ENV=production. Nothing it does leaves this server and no
 * money moves.
 */
final class SimulatedPaymentGateway implements PaymentGateway
{
    private const KEY = 'billing.simulator.';

    /** How long a checkout, and a charge to refund, is remembered. */
    private const KEEP_DAYS = 30;

    public function __construct(
        private readonly Repository $cache,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function checkout(CardCheckout $checkout): CheckoutSession
    {
        if ((float) $checkout->amount <= 0) {
            throw new PaymentGatewayException(__('A card payment is more than zero.'));
        }

        $id = 'sim_'.Str::lower((string) Str::ulid());

        $this->cache->put(self::KEY.$id, [
            'amount' => $checkout->amount,
            'currency' => $checkout->currency,
            'reference' => $checkout->reference,
            'description' => $checkout->description,
            'return_url' => $checkout->returnUrl,
            'state' => CardCharge::PENDING,
        ], now()->addDays(self::KEEP_DAYS));

        return new CheckoutSession($id, route('billing.simulator.show', $id));
    }

    public function charge(string $sessionId): CardCharge
    {
        $session = $this->session($sessionId);

        return match ($session['state']) {
            CardCharge::SUCCEEDED => new CardCharge(CardCharge::SUCCEEDED, 'txn_'.$sessionId, $session['amount'], $session['currency'], $this->fee($session['amount'])),
            CardCharge::FAILED => new CardCharge(CardCharge::FAILED, null, $session['amount'], $session['currency'], failure: __('The card was declined.')),
            default => new CardCharge(CardCharge::PENDING, null, $session['amount'], $session['currency']),
        };
    }

    public function refund(string $transactionId, string $amount, string $currency, string $reference): CardRefund
    {
        $session = $this->session(substr($transactionId, 4));

        if ($session['state'] !== CardCharge::SUCCEEDED || strtoupper($currency) !== strtoupper($session['currency'])) {
            return new CardRefund(false, null, __('There is no such card payment to refund.'));
        }

        if ((float) $amount <= 0 || (float) $amount > (float) $session['amount']) {
            return new CardRefund(false, null, __('A refund to a card is no more than the payment it took.'));
        }

        return new CardRefund(true, 'rfd_'.Str::lower((string) Str::ulid()));
    }

    /**
     * The checkout as the simulator's page shows it.
     *
     * @return array{amount: string, currency: string, reference: string, description: string, return_url: string, state: string}
     */
    public function session(string $sessionId): array
    {
        $session = $this->cache->get(self::KEY.$sessionId);

        if (! is_array($session)) {
            throw new PaymentGatewayException(__('There is no such card payment.'));
        }

        return $session;
    }

    /**
     * Approve or decline a waiting checkout, as the customer does on the
     * simulator's page.
     *
     * @return string where the customer goes back to
     */
    public function decide(string $sessionId, bool $approve): string
    {
        $session = $this->session($sessionId);

        if ($session['state'] === CardCharge::PENDING) {
            $session['state'] = $approve ? CardCharge::SUCCEEDED : CardCharge::FAILED;
            $this->cache->put(self::KEY.$sessionId, $session, now()->addDays(self::KEEP_DAYS));
        }

        return $session['return_url'].(str_contains($session['return_url'], '?') ? '&' : '?').'session='.urlencode($sessionId);
    }

    private function fee(string $amount): string
    {
        return number_format(round((float) $amount * (float) config('billing.simulator.fee_percent', 0) / 100, 2), 2, '.', '');
    }
}
