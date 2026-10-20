<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Contracts;

use App\Domains\Billing\Reconciliation\DTOs\ParsedStatement;
use App\Domains\Billing\Reconciliation\StatementRejected;

/**
 * The card provider's settlement report, whichever provider is contracted:
 * what it took from customers' cards over a period, what it kept, and what
 * it paid out to the platform's bank account.
 *
 * Each line is one card transaction: the gateway's transaction id (what a
 * card payment is recorded with, payments.gateway_reference), the
 * reference the platform gave the checkout (the invoice's number), the
 * gross the customer paid, the provider's fee, and the net it paid out
 * (gross less fee: a line that does not add up refuses the report). A
 * refund is a line of money out.
 *
 * Bound with the card gateway (billing.gateway): the simulator's reader
 * for the simulator, none while there is no gateway. A real provider's is
 * a class implementing this, named by what it is for, never by the
 * provider.
 */
interface SettlementReport
{
    /** Whether a settlement report can be read at all. */
    public function available(): bool;

    /** The file types its reports come as, as a file input accepts them: ".csv". */
    public function accepts(): string;

    /**
     * @throws StatementRejected saying what is wrong with the file
     */
    public function parse(string $bytes): ParsedStatement;
}
