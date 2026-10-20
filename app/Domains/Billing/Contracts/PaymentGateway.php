<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\DTOs\CardCharge;
use App\Domains\Billing\DTOs\CardCheckout;
use App\Domains\Billing\DTOs\CardRefund;
use App\Domains\Billing\DTOs\CheckoutSession;
use App\Domains\Billing\Exceptions\PaymentGatewayException;

/**
 * A card payment provider, whichever one is contracted: the platform never
 * sees a card number.
 *
 * A customer is sent to the provider's own page for the amount
 * (checkout), and comes back to returnUrl; the platform then asks the
 * provider how it ended (charge) and records what the provider says, never
 * what the browser says. A refund goes back to the card a charge took.
 *
 * Bound by configuration (billing.gateway): empty is no gateway, simulator
 * the simulator. A real provider is a class implementing this, named by
 * what it is for, never by the provider.
 */
interface PaymentGateway
{
    /** Whether card payments can be taken at all. */
    public function available(): bool;

    /**
     * @throws PaymentGatewayException when the provider will not start it
     */
    public function checkout(CardCheckout $checkout): CheckoutSession;

    /**
     * How a checkout ended, from the provider.
     *
     * @throws PaymentGatewayException when the provider cannot be asked
     */
    public function charge(string $sessionId): CardCharge;

    /**
     * @throws PaymentGatewayException when the provider will not refund it
     */
    public function refund(string $transactionId, string $amount, string $currency, string $reference): CardRefund;
}
