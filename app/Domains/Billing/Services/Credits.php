<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Models\RefundIntent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * A customer's credit with the platform: money to its name that its next
 * invoices take before anything is asked of it.
 *
 * Money the customer already has with the platform - an overpayment, what
 * a credit note left paid beyond what was owed, an admin's adjustment -
 * goes here with its source and record (grant()), and the next invoice in
 * that currency takes it as it would a payment (Receivables::issue). A
 * reward that lowers a price - a referral's - is a discount instead, from
 * the CreditSource. What is left of a credit is its amount less what
 * invoices took from it and what was paid back out of it, worked out each
 * time rather than kept, so it cannot drift from the record.
 */
class Credits
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws DomainException for an amount that is not positive, or a source it does not know
     */
    public function grant(
        string $organizationId,
        string $currency,
        string $amount,
        string $source,
        ?string $sourceId,
        ?string $reason,
        ?CarbonImmutable $expiresOn = null,
        ?CarbonImmutable $grantedOn = null,
    ): BillingCredit {
        if (! in_array($source, BillingCredit::SOURCES, true)) {
            throw new DomainException("No such credit source: {$source}");
        }

        if ((float) $amount <= 0) {
            throw new DomainException(__('A credit is a positive amount.'));
        }

        $credit = BillingCredit::withoutTenantScope(fn () => BillingCredit::query()->create([
            'org_id' => $organizationId,
            'currency' => strtoupper($currency),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'source' => $source,
            'source_id' => $sourceId,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'granted_on' => ($grantedOn ?? CarbonImmutable::now())->toDateString(),
            'expires_on' => $expiresOn?->toDateString(),
        ]));

        $this->audit->logFor($organizationId, 'finance.credit.granted', $credit, [
            'currency' => $credit->currency, 'amount' => (string) $credit->amount, 'source' => $source, 'source_id' => $sourceId,
        ]);

        return $credit;
    }

    public function find(string $organizationId, string $creditId): ?BillingCredit
    {
        return BillingCredit::withoutTenantScope(fn () => BillingCredit::query()->where('org_id', $organizationId)->find($creditId));
    }

    /**
     * A credit that should never have been: what an overpayment recorded in
     * error left (Payments::reverse). Only one nothing has been taken from.
     *
     * @throws DomainException when an invoice took some of it, or it was paid back
     */
    public function reverse(BillingCredit $credit, string $reason, ?string $actorId): BillingCredit
    {
        $locked = BillingCredit::withoutTenantScope(fn () => BillingCredit::query()->lockForUpdate()->findOrFail($credit->id));

        if ($locked->reversed_at !== null) {
            return $locked;
        }

        if (bccomp($this->left($locked), (string) $locked->amount, 2) !== 0) {
            throw new DomainException(__('The :amount credit it left has been used since. Reverse what used it first.', ['amount' => (string) $locked->amount.' '.$locked->currency]));
        }

        $locked->forceFill(['reversed_at' => now(), 'reversed_by' => $actorId, 'reversal_reason' => mb_substr($reason, 0, 500)])->save();

        $this->audit->logFor((string) $locked->org_id, 'finance.credit.reversed', $locked, [
            'currency' => $locked->currency, 'amount' => (string) $locked->amount, 'reason' => $reason,
        ]);

        return $locked;
    }

    /**
     * What is left of one credit, as "25.00": nothing, once it is reversed.
     * What is on its way back to a card (a refund intent, paying) is spent
     * already, so nothing else takes it meanwhile.
     */
    public function left(BillingCredit $credit): string
    {
        if ($credit->reversed_at !== null) {
            return '0.00';
        }

        $spent = (float) $credit->allocations()->withoutGlobalScopes()->sum('amount')
            + (float) Refund::withoutTenantScope(fn () => Refund::query()->where('credit_id', $credit->id)->sum('payout_amount'))
            + (float) RefundIntent::withoutTenantScope(fn () => RefundIntent::query()->where('credit_id', $credit->id)->where('status', RefundIntent::PAYING)->sum('payout_amount'));

        return number_format(max(0.0, round((float) $credit->amount - $spent, 2)), 2, '.', '');
    }

    /**
     * The customer's credits in a currency with something left and not
     * lapsed on the day, those that lapse first first.
     *
     * @return Collection<int, array{credit: BillingCredit, left: string}>
     */
    public function available(string $organizationId, string $currency, ?CarbonImmutable $on = null): Collection
    {
        $on ??= CarbonImmutable::now();

        return BillingCredit::withoutTenantScope(fn () => BillingCredit::query()
            ->where('org_id', $organizationId)
            ->where('currency', strtoupper($currency))
            ->whereNull('reversed_at')
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', $on->toDateString()))
            ->orderByRaw('case when expires_on is null then 1 else 0 end')
            ->orderBy('expires_on')
            ->orderBy('granted_on')
            ->get())
            ->map(fn (BillingCredit $credit): array => ['credit' => $credit, 'left' => $this->left($credit)])
            ->filter(fn (array $row): bool => (float) $row['left'] > 0)
            ->values();
    }

    /** All the customer has to its credit in a currency, as "25.00". */
    public function balance(string $organizationId, string $currency, ?CarbonImmutable $on = null): string
    {
        return number_format($this->available($organizationId, $currency, $on)->sum(fn (array $row): float => (float) $row['left']), 2, '.', '');
    }
}
