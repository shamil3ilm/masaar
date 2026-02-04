<?php

declare(strict_types=1);

namespace App\Listeners\Sales;

use App\Events\Sales\InvoicePosted;
use App\Events\Sales\PaymentReceived;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateCustomerBalanceListener implements ShouldQueue
{
    public string $queue = 'default';

    public function handleInvoicePosted(InvoicePosted $event): void
    {
        $invoice = $event->invoice;
        $customer = $invoice->customer;

        if (!$customer) {
            return;
        }

        // Update customer's outstanding balance
        // This is a denormalized field for quick access
        $customer->updateOutstandingBalance();
    }

    public function handlePaymentReceived(PaymentReceived $event): void
    {
        $payment = $event->payment;
        $customer = $payment->customer;

        if (!$customer) {
            return;
        }

        // Update customer's outstanding balance
        $customer->updateOutstandingBalance();
    }
}
