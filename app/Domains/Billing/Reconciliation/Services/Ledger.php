<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Services;

use App\Domains\Billing\Enums\PaymentChannel;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatchItem;
use App\Domains\Billing\Services\Receivables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a statement line can be matched to, read for the matcher: every
 * customer's invoices still owed in a currency, and the payments recorded
 * that no confirmed match has claimed yet.
 */
class Ledger
{
    public function __construct(
        private readonly Receivables $receivables,
    ) {}

    /**
     * @return Collection<int, array{receivable: Receivable, number: string, balance: string, names: list<string>}>
     */
    public function owed(string $currency): Collection
    {
        $owed = Receivable::withoutTenantScope(fn () => Receivable::query()
            ->withoutGlobalScopes()
            ->with('organization')
            ->where('currency', strtoupper($currency))
            ->whereIn('status', ReceivableStatus::outstandingValues())
            ->orderBy('due_date')
            ->orderBy('issue_date')
            ->get());
        $numbers = $this->receivables->numbers($owed);

        return $owed->map(fn (Receivable $receivable): array => [
            'receivable' => $receivable,
            'number' => $numbers[(string) $receivable->id] ?? '',
            'balance' => $receivable->balance(),
            'names' => array_values(array_filter([(string) $receivable->organization?->name, (string) $receivable->organization?->name_ar])),
        ])->filter(fn (array $row): bool => (float) $row['balance'] > 0)->values();
    }

    /**
     * Payments recorded in a currency through a channel, not reversed, that
     * no confirmed match has claimed.
     *
     * @return Collection<int, Payment>
     */
    public function unclaimed(string $currency, PaymentChannel $channel): Collection
    {
        return Payment::withoutTenantScope(fn () => $this->unclaimedQuery()
            ->with('organization')
            ->where('currency', strtoupper($currency))
            ->where('channel', $channel->value)
            ->get());
    }

    public function isClaimed(string $paymentId): bool
    {
        return ! Payment::withoutTenantScope(fn () => $this->unclaimedQuery()->whereKey($paymentId)->exists());
    }

    /**
     * Money the platform paid back to customers in a currency, by bank
     * transfer or to a card, that no confirmed match has claimed.
     *
     * @param  string  $method  bank_transfer or card
     * @return Collection<int, Refund>
     */
    public function unclaimedRefunds(string $currency, string $method): Collection
    {
        return Refund::withoutTenantScope(fn () => $this->unclaimedRefundQuery()
            ->where('currency', strtoupper($currency))
            ->where('payout_method', $method)
            ->where('payout_amount', '>', 0)
            ->get());
    }

    public function isRefundClaimed(string $refundId): bool
    {
        return ! Refund::withoutTenantScope(fn () => $this->unclaimedRefundQuery()->whereKey($refundId)->exists());
    }

    /** @return Builder<Refund> */
    private function unclaimedRefundQuery(): Builder
    {
        return Refund::query()
            ->withoutGlobalScopes()
            ->whereNotIn('id', ReconciliationMatchItem::query()
                ->select('refund_id')
                ->whereNotNull('refund_id')
                ->where('role', ReconciliationMatchItem::REFUND)
                ->whereHas('reconciliationMatch', fn ($query) => $query->withoutGlobalScopes()->where('status', MatchStatus::Confirmed->value)));
    }

    /** @return Builder<Payment> */
    private function unclaimedQuery(): Builder
    {
        return Payment::query()
            ->withoutGlobalScopes()
            ->whereNull('reversed_at')
            ->whereNotIn('id', ReconciliationMatchItem::query()
                ->select('payment_id')
                ->whereNotNull('payment_id')
                ->whereIn('role', [ReconciliationMatchItem::PAYMENT, ReconciliationMatchItem::CREATED])
                ->whereHas('reconciliationMatch', fn ($query) => $query->withoutGlobalScopes()->where('status', MatchStatus::Confirmed->value)));
    }
}
