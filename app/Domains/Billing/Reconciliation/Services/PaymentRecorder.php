<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Services;

use App\Domains\Billing\DTOs\ReceivedPayment;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatchItem;
use App\Domains\Billing\Reconciliation\Models\StatementImport;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Services\Payments;
use App\Domains\Billing\Services\PlatformInvoices;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * The payments a confirmed match records, one per statement line, through
 * Payments as an admin recording them would:
 *
 *  - a bank line is a transfer into the account it is the statement of,
 *    on the day it was booked, with the line's reference; a shortfall the
 *    match takes as the bank's fee is the payment's fee, the customer
 *    credited in full;
 *  - a card settlement line is the card payment the gateway took, its fee
 *    the gateway's, recorded once per gateway transaction;
 *  - in another currency than the tax currency, the day's rate from the
 *    rate table, so the exchange difference against each invoice's own
 *    rate is kept on its allocation.
 *
 * Each line's money goes to the match's invoices in turn, each up to what
 * the match gives it and what it still owes; what is left of a line is
 * the customer's credit (Payments::allocate).
 */
class PaymentRecorder
{
    public function __construct(
        private readonly Payments $payments,
        private readonly Receivables $receivables,
        private readonly ExchangeRates $rates,
        private readonly PlatformInvoices $platform,
    ) {}

    /**
     * @param  Collection<int, StatementLine>  $lines
     * @param  Collection<int, ReconciliationMatchItem>  $targets
     * @return array<string, Payment> statement line id => the payment recorded for it
     *
     * @throws DomainException
     */
    public function record(ReconciliationMatch $match, Organization $customer, Collection $lines, Collection $targets, ?string $actorId): array
    {
        $left = $this->owed($match, $targets);
        $fee = $match->fee_amount === null ? null : (string) $match->fee_amount;
        $recorded = [];

        foreach ($lines as $line) {
            // The shortfall taken as the bank's fee is one line's.
            $lineFee = $lines->count() === 1 ? $fee : null;
            $credited = bcadd((string) $line->amount, $lineFee ?? '0', 2);
            [$allocations, $left] = $this->take($credited, $left);
            $recorded[(string) $line->id] = $this->recordLine($customer, $line, $credited, $lineFee, $allocations, $actorId);
        }

        return $recorded;
    }

    /**
     * What each invoice takes: what the match gives it, no more than it
     * still owes now.
     *
     * @param  Collection<int, ReconciliationMatchItem>  $targets
     * @return array<string, string>
     *
     * @throws DomainException
     */
    private function owed(ReconciliationMatch $match, Collection $targets): array
    {
        $left = [];

        foreach ($targets as $target) {
            $receivable = $this->receivables->find((string) $match->org_id, (string) $target->receivable_id)
                ?? throw new DomainException(__('An invoice of this match is no longer there.'));

            if (bccomp($receivable->balance(), '0', 2) <= 0) {
                throw new DomainException(__('Invoice :number is already paid. Reject this match, or match the line by hand.', ['number' => $this->receivables->number($receivable)]));
            }

            $left[(string) $receivable->id] = bccomp((string) $target->amount, $receivable->balance(), 2) > 0 ? $receivable->balance() : (string) $target->amount;
        }

        return $left;
    }

    /**
     * @param  array<string, string>  $left
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function take(string $credited, array $left): array
    {
        $allocations = [];

        foreach ($left as $id => $owed) {
            if (bccomp($credited, '0', 2) <= 0) {
                break;
            }

            $amount = bccomp($credited, $owed, 2) < 0 ? $credited : $owed;

            if (bccomp($amount, '0', 2) > 0) {
                $allocations[$id] = $amount;
                $left[$id] = bcsub($owed, $amount, 2);
                $credited = bcsub($credited, $amount, 2);
            }
        }

        // Nothing named would set it against the customer's oldest invoices:
        // a line beyond what the match's invoices owe is all credit.
        if ($allocations === [] && $left !== []) {
            $allocations[array_key_first($left)] = '0';
        }

        return [$allocations, $left];
    }

    /**
     * @param  array<string, string>  $allocations
     *
     * @throws DomainException
     */
    private function recordLine(Organization $customer, StatementLine $line, string $credited, ?string $fee, array $allocations, ?string $actorId): Payment
    {
        $received = new ReceivedPayment(
            amount: $credited,
            currency: (string) $line->currency,
            receivedOn: CarbonImmutable::parse($line->booked_on->toDateString()),
            reference: $line->reference ?? $line->bank_reference,
            bankAccount: $line->source === StatementImport::GATEWAY ? null : $line->source,
            fee: $line->source === StatementImport::GATEWAY && $line->fee_amount !== null ? bcadd((string) $line->fee_amount, $fee ?? '0', 2) : $fee,
            exchangeRate: $this->rate((string) $line->currency, CarbonImmutable::parse($line->booked_on->toDateString())),
            allocations: $allocations,
        );

        return $line->source === StatementImport::GATEWAY
            ? $this->payments->recordSettledCard($customer, $received, (string) $line->gateway_reference, $actorId)
            : $this->payments->recordTransfer($customer, $received, $actorId);
    }

    private function rate(string $currency, CarbonImmutable $on): ?string
    {
        return strtoupper($currency) === $this->platform->taxCurrency() ? null : ($this->rates->on($currency, $on)['rate'] ?? null);
    }
}
