<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Refund;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * A customer's credit with the platform: money to its name that its next
 * invoices take before anything is asked of it.
 *
 * This is where anything that rewards or refunds a customer puts the money
 * - the referral programme's rewards included: grant() with its source and
 * the record it came from, and the next invoice in that currency takes it
 * (Receivables::issue). What is left of a credit is its amount less what
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

    /** What is left of one credit, as "25.00". */
    public function left(BillingCredit $credit): string
    {
        $spent = (float) $credit->allocations()->withoutGlobalScopes()->sum('amount')
            + (float) Refund::withoutTenantScope(fn () => Refund::query()->where('credit_id', $credit->id)->sum('payout_amount'));

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
