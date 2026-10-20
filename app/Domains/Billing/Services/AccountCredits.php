<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Contracts\CreditSource;
use App\Domains\Billing\DTOs\AvailableCredit;
use App\Domains\Billing\Models\AccountCredit;
use App\Domains\Billing\Models\Payment;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Credit organizations hold against their next invoices: granted with a
 * source and a reason (a referral reward), redeemed by invoicing through
 * CreditSource, and adjusted or reversed by an admin while it is unused.
 *
 * Every grant, adjustment, reversal and redemption is in the audit log,
 * under the organization that holds the credit.
 */
class AccountCredits implements CreditSource
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  'amount'|'percent'  $kind
     */
    public function grant(
        string $organizationId,
        string $kind,
        ?string $amount,
        ?string $percent,
        ?string $currency,
        string $description,
        string $source,
        ?string $sourceId,
        ?CarbonImmutable $expiresAt,
        ?string $actorId,
    ): AccountCredit {
        $credit = AccountCredit::query()->create([
            'org_id' => $organizationId,
            'source' => $source,
            'source_id' => $sourceId,
            'kind' => $kind,
            'amount' => $kind === AvailableCredit::AMOUNT ? $amount : null,
            'percent' => $kind === AvailableCredit::PERCENT ? $percent : null,
            'currency' => $kind === AvailableCredit::AMOUNT ? $currency : null,
            'description' => mb_substr($description, 0, 255),
            'status' => AccountCredit::AVAILABLE,
            'expires_at' => $expiresAt,
        ]);

        $this->record('credit.granted', $credit, null, $this->values($credit), $actorId);

        return $credit;
    }

    /**
     * The sum of an organization's usable credit in a currency.
     */
    public function outstanding(string $organizationId, string $currency): string
    {
        return $this->money((float) $this->usable($organizationId)
            ->where('kind', AvailableCredit::AMOUNT)
            ->where('currency', $currency)
            ->sum(fn (AccountCredit $credit): float => (float) $credit->amount));
    }

    public function available(string $organizationId, string $currency): array
    {
        return $this->usable($organizationId)
            ->filter(fn (AccountCredit $credit): bool => $credit->kind === AvailableCredit::PERCENT || $credit->currency === $currency)
            ->map(fn (AccountCredit $credit): AvailableCredit => new AvailableCredit(
                id: (string) $credit->id,
                organizationId: $organizationId,
                kind: (string) $credit->kind,
                amount: $credit->amount,
                percent: $credit->percent,
                currency: $credit->currency,
                description: (string) $credit->description,
                source: (string) $credit->source,
                sourceId: $credit->source_id,
                expiresAt: $credit->expires_at === null ? null : CarbonImmutable::instance($credit->expires_at),
            ))
            ->values()
            ->all();
    }

    public function redeem(string $creditId, string $invoiceId, string $amount): void
    {
        DB::transaction(function () use ($creditId, $invoiceId, $amount): void {
            $credit = $this->locked($creditId);

            if (! $credit->usable()) {
                throw new DomainException('This credit is '.$credit->status.' or past its expiry, and cannot be used.');
            }

            if ($credit->kind === AvailableCredit::AMOUNT && (float) $amount > (float) $credit->amount) {
                throw new DomainException('This credit holds '.$credit->amount.' '.$credit->currency.', less than '.$amount.'.');
            }

            $credit->forceFill([
                'status' => AccountCredit::REDEEMED,
                'redeemed_invoice_id' => $invoiceId,
                'redeemed_amount' => $amount,
                'redeemed_at' => now(),
            ])->save();

            $this->record('credit.redeemed', $credit, null, ['invoice_id' => $invoiceId, 'amount' => $amount], null);
        });
    }

    /**
     * A new value for an unused credit: a sum or a percentage.
     *
     * @throws DomainException once it is used or reversed
     */
    public function adjust(string $creditId, string $value, string $reason, string $actorId): AccountCredit
    {
        return DB::transaction(function () use ($creditId, $value, $reason, $actorId): AccountCredit {
            $credit = $this->locked($creditId);

            if ($credit->status !== AccountCredit::AVAILABLE) {
                throw new DomainException('Only an unused credit can be adjusted; this one is '.$credit->status.'.');
            }

            $before = $this->values($credit);
            $credit->forceFill([$credit->kind === AvailableCredit::PERCENT ? 'percent' : 'amount' => $value])->save();

            $this->record('credit.adjusted', $credit, $before, [...$this->values($credit), 'reason' => $reason], $actorId);

            return $credit;
        });
    }

    /**
     * Taken back, while unused.
     *
     * @throws DomainException once it is used
     */
    public function reverse(string $creditId, string $reason, string $actorId): void
    {
        DB::transaction(function () use ($creditId, $reason, $actorId): void {
            $credit = $this->locked($creditId);

            if ($credit->status === AccountCredit::REVERSED) {
                return;
            }

            if ($credit->status === AccountCredit::REDEEMED) {
                throw new DomainException('This credit was used on an invoice already. Correct that invoice with a credit note instead.');
            }

            $credit->forceFill([
                'status' => AccountCredit::REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => mb_substr($reason, 0, 500),
            ])->save();

            $this->record('credit.reversed', $credit, null, ['reason' => $reason], $actorId);
        });
    }

    /**
     * An organization's credits, newest first, for its pages.
     *
     * @return Collection<int, AccountCredit>
     */
    public function of(string $organizationId): Collection
    {
        return AccountCredit::query()->where('org_id', $organizationId)->orderByDesc('created_at')->get();
    }

    /**
     * Credits from one source, for its report. Sums by currency - what was
     * granted, what invoices used, what is still usable - and percentages
     * off, which have no currency, by number. Reversed credits are left out.
     *
     * @return array{amounts: array<string, array{granted: string, redeemed: string, outstanding: string}>, percent: array{granted: int, redeemed: int, outstanding: int}}
     */
    public function totals(string $source): array
    {
        $amounts = [];
        $percent = ['granted' => 0, 'redeemed' => 0, 'outstanding' => 0];

        foreach (AccountCredit::query()->where('source', $source)->where('status', '!=', AccountCredit::REVERSED)->get() as $credit) {
            if ($credit->kind === AvailableCredit::PERCENT) {
                $percent['granted']++;
                $percent['redeemed'] += $credit->status === AccountCredit::REDEEMED ? 1 : 0;
                $percent['outstanding'] += $credit->usable() ? 1 : 0;

                continue;
            }

            $row = $amounts[(string) $credit->currency] ?? ['granted' => 0.0, 'redeemed' => 0.0, 'outstanding' => 0.0];
            $row['granted'] += (float) $credit->amount;
            $row['redeemed'] += $credit->status === AccountCredit::REDEEMED ? (float) $credit->redeemed_amount : 0.0;
            $row['outstanding'] += $credit->usable() ? (float) $credit->amount : 0.0;
            $amounts[(string) $credit->currency] = $row;
        }

        ksort($amounts);

        return [
            'amounts' => array_map(fn (array $row): array => array_map(fn (float $value): string => $this->money($value), $row), $amounts),
            'percent' => $percent,
        ];
    }

    /**
     * The currency an organization is billed in: that of its latest payment,
     * or the platform's own until it has paid.
     */
    public function currencyOf(string $organizationId): string
    {
        $latest = Payment::withoutTenantScope(fn () => Payment::query()->where('org_id', $organizationId)->latest('paid_at')->value('currency'));

        return is_string($latest) && $latest !== '' ? $latest : (string) config('billing.currency', 'SAR');
    }

    /**
     * @return Collection<int, AccountCredit>
     */
    private function usable(string $organizationId): Collection
    {
        return AccountCredit::query()
            ->where('org_id', $organizationId)
            ->where('status', AccountCredit::AVAILABLE)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('created_at')
            ->get();
    }

    private function locked(string $creditId): AccountCredit
    {
        return AccountCredit::query()->whereKey($creditId)->lockForUpdate()->first()
            ?? throw new DomainException('There is no credit '.$creditId.'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function values(AccountCredit $credit): array
    {
        return array_filter([
            'kind' => $credit->kind,
            'amount' => $credit->amount,
            'percent' => $credit->percent,
            'currency' => $credit->currency,
            'source' => $credit->source,
            'source_id' => $credit->source_id,
            'expires_at' => $credit->expires_at?->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function record(string $action, AccountCredit $credit, ?array $before, array $after, ?string $actorId): void
    {
        $this->audit->logChange($action, 'AccountCredit', (string) $credit->id, $before, $after, [], $actorId, (string) $credit->org_id);
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
