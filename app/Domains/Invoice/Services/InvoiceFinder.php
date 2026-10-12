<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Looks up an organization's invoices.
 *
 * Every lookup names the organization explicitly as well as passing through
 * BelongsToTenant's scope, so another tenant's id is not found even where the
 * scope stands down. A null organization matches no invoice.
 */
class InvoiceFinder
{
    /**
     * @throws ModelNotFoundException
     */
    public function find(?string $organizationId, string $id): Invoice
    {
        return $this->ofOrganization($organizationId)->findOrFail($id);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function findWithLines(?string $organizationId, string $id): Invoice
    {
        return $this->ofOrganization($organizationId)
            ->with('lines')
            ->findOrFail($id);
    }

    /**
     * Newest first, optionally only those in one status.
     */
    public function paginate(?string $organizationId, ?string $status, int $perPage): LengthAwarePaginator
    {
        return $this->ofOrganization($organizationId)
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            // Tiebreaker - see InvoicePagingTest. created_at is a
            // second-precision timestamp, so a batch issued in one second
            // ties and the planner may order the tie differently per page.
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * The portal's list: newest first, narrowed by status and a text search.
     *
     * Separate from paginate() rather than adding arguments to it, because
     * that one is the API's and its signature is a contract.
     *
     * The search is deliberately two columns. An invoice number is what a
     * taxpayer has in front of them when they come looking, and a buyer name
     * is what they have when they do not. Searching anything else - totals,
     * the hash, the XML - costs an index scan for a question nobody asks.
     */
    public function search(
        ?string $organizationId,
        ?string $status,
        ?string $query,
        int $perPage,
    ): LengthAwarePaginator {
        $term = is_string($query) ? trim($query) : '';

        return $this->ofOrganization($organizationId)
            ->when($status !== null && $status !== '', fn (Builder $q) => $q->where('status', $status))
            ->when($term !== '', fn (Builder $q) => $q->where(
                // Grouped, or the status filter above would be ORed away and
                // the list would show every tenant status at once.
                fn (Builder $inner) => $inner
                    ->where('invoice_number', 'like', '%'.$term.'%')
                    ->orWhere('buyer_name', 'like', '%'.$term.'%')
            ))
            ->orderBy('created_at', 'desc')
            // Tiebreaker - see InvoicePagingTest. created_at is a
            // second-precision timestamp, so a batch issued in one second
            // ties and the planner may order the tie differently per page.
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function ofOrganization(?string $organizationId): Builder
    {
        return Invoice::where('org_id', $organizationId);
    }
}
