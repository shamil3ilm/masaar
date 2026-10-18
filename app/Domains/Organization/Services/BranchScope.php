<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The branches the current request reads, as a query condition.
 *
 * Two layers. What the signed-in person may see (allowed) is enforced on
 * every read of an invoice, in the portal and the API alike, so a cashier's
 * restriction holds in the query itself rather than only in the screens
 * that hide another branch. What they chose in the portal's branch switcher
 * (selected) narrows the lists, the dashboard and the reports further, and
 * never widens: a choice outside what they may see is refused when it is
 * made (PortalBranch).
 *
 * Kept on the request rather than on this object, so each request starts
 * clean - a queue worker or a command has none, and reads everything, as it
 * always has - and every service that applies it agrees on one answer
 * without having to be the same instance. A report that breaks the figures
 * down by branch narrows to one branch at a time with within().
 *
 * Writing an invoice never passes through here: what a branch issues is
 * decided by BranchAccess::draftBranch, and issuing reads a device's chain,
 * which no person's branch may hide.
 */
class BranchScope
{
    /** In a list of branch ids: documents that name no branch - head office. */
    public const NONE = 'none';

    private const SELECTED = 'branch_scope.selected';

    private const ALLOWED = 'branch_scope.allowed';

    private const WITHIN = 'branch_scope.within';

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BranchAccess $access,
    ) {}

    /**
     * The branches the signed-in person may see in the current organization:
     * null for every one. A machine credential, a command and a queue worker
     * hold no person, and see every one.
     *
     * @return list<string>|null
     */
    public function allowed(): ?array
    {
        $organizationId = $this->tenant->getOrganizationId();
        $userId = auth()->id();

        if ($organizationId === null || $userId === null || $this->tenant->getRole() === 'service') {
            return null;
        }

        $key = $organizationId.'|'.$userId;
        $cached = request()->attributes->get(self::ALLOWED);

        if (is_array($cached) && array_key_exists($key, $cached)) {
            return $cached[$key];
        }

        $allowed = $this->access->allowed((string) $userId, $organizationId);
        request()->attributes->set(self::ALLOWED, [...(is_array($cached) ? $cached : []), $key => $allowed]);

        return $allowed;
    }

    /** The branch chosen in the switcher, or null for all of them. */
    public function selected(): ?string
    {
        $selected = request()->attributes->get(self::SELECTED);

        return is_string($selected) ? $selected : null;
    }

    /** Record the switcher's choice for this request (PortalBranch). */
    public function select(?string $branchId): void
    {
        request()->attributes->set(self::SELECTED, $branchId);
    }

    /**
     * What the lists, the dashboard and the reports show: one branch while
     * a breakdown is drawn, the chosen branch, or everything allowed.
     *
     * @return list<string>|null
     */
    public function visible(): ?array
    {
        if (request()->attributes->has(self::WITHIN)) {
            return request()->attributes->get(self::WITHIN);
        }

        $selected = $this->selected();

        return $selected !== null ? [$selected] : $this->allowed();
    }

    /**
     * Run a callback reading exactly these branches (null: all), for a figure
     * per branch. The caller hands in branches it has already been allowed
     * - visible() or an allowed() - so this cannot widen what is seen.
     *
     * @template T
     *
     * @param  list<string>|null  $branchIds
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(?array $branchIds, Closure $callback): mixed
    {
        $attributes = request()->attributes;
        $had = $attributes->has(self::WITHIN);
        $previous = $attributes->get(self::WITHIN);
        $attributes->set(self::WITHIN, $branchIds);

        try {
            return $callback();
        } finally {
            $had ? $attributes->set(self::WITHIN, $previous) : $attributes->remove(self::WITHIN);
        }
    }

    /**
     * Narrow a query to what the person may see.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyAllowed(Builder $query, string $column = 'branch_id'): Builder
    {
        return $this->constrain($query, $this->allowed(), $column);
    }

    /**
     * Narrow a query to what the lists and reports show.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyVisible(Builder $query, string $column = 'branch_id'): Builder
    {
        return $this->constrain($query, $this->visible(), $column);
    }

    /**
     * The same, for rows that belong to an invoice - a submission - rather
     * than carrying a branch of their own.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyVisibleByInvoice(Builder $query, string $invoiceColumn = 'invoice_id'): Builder
    {
        $branches = $this->visible();

        if ($branches === null) {
            return $query;
        }

        return $query->whereIn($invoiceColumn, fn ($invoices) => $this->constrain($invoices->select('id')->from('invoices'), $branches, 'branch_id'));
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  list<string>|null  $branchIds
     * @return TBuilder
     */
    private function constrain(Builder $query, ?array $branchIds, string $column): Builder
    {
        if ($branchIds === null) {
            return $query;
        }

        $ids = array_values(array_filter($branchIds, fn (string $id): bool => $id !== self::NONE));
        $none = count($ids) !== count($branchIds);

        if ($ids === [] && ! $none) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $inner) => $inner
            ->when($ids !== [], fn (Builder $q) => $q->whereIn($column, $ids))
            ->when($none, fn (Builder $q) => $q->orWhereNull($column)));
    }
}
