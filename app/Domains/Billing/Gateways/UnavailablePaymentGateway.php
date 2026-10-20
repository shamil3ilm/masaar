<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\DTOs\CardCharge;
use App\Domains\Billing\DTOs\CardCheckout;
use App\Domains\Billing\DTOs\CardRefund;
use App\Domains\Billing\DTOs\CheckoutSession;
use App\Domains\Billing\Exceptions\PaymentGatewayException;

/**
 * No card provider contracted yet (billing.gateway empty): card payments
 * are not offered, and anything that asks is told why.
 */
final class UnavailablePaymentGateway implements PaymentGateway
{
    public function available(): bool
    {
        return false;
    }

    public function checkout(CardCheckout $checkout): CheckoutSession
    {
        throw $this->none();
    }

    public function charge(string $sessionId): CardCharge
    {
        throw $this->none();
    }

    public function refund(string $transactionId, string $amount, string $currency, string $reference): CardRefund
    {
        throw $this->none();
    }

    private function none(): PaymentGatewayException
    {
        return new PaymentGatewayException(__('Card payments are not available: no card gateway is set up yet.'));
    }
}
