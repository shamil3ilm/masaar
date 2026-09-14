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
            ->paginate($perPage);
    }

    private function ofOrganization(?string $organizationId): Builder
    {
        return Invoice::where('org_id', $organizationId);
    }
}
