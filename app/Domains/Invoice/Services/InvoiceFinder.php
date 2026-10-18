<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Looks up an organization's invoices.
 *
 * Every lookup names the organization explicitly as well as passing through
 * BelongsToTenant's scope, so another tenant's id is not found even where the
 * scope stands down. A null organization matches no invoice.
 *
 * And the branches the signed-in person may see (BranchScope): a cashier
 * assigned to one branch finds another branch's invoice no more than another
 * tenant's, whether by URL or through the API.
 */
class InvoiceFinder
{
    public function __construct(
        private readonly BranchScope $branches,
    ) {}

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

    private function ofOrganization(?string $organizationId): Builder
    {
        return $this->branches->applyAllowed(Invoice::where('org_id', $organizationId));
    }
}
