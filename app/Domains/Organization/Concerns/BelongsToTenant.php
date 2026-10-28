<?php

declare(strict_types=1);

namespace App\Domains\Organization\Concerns;

use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Confines a model's queries to the current tenant.
 *
 * Tenant isolation is the platform's most important invariant: one taxpayer
 * must never see another's invoices. Enforcing it by writing
 * `where('org_id', ...)` in every query works only for as long as
 * every future author remembers, and nothing fails when one does not. This
 * makes the default safe and the exception explicit.
 *
 * Three situations, deliberately different:
 *
 *   Tenant context present   scope to that organization (a request's, or
 *                            one a command or job declared with
 *                            TenantResolver::runAs()).
 *   No context, in HTTP      match nothing (1 = 0). An authenticated request
 *                            that somehow lost its tenant returns empty
 *                            rather than everything.
 *   Console and queue        no scope. Commands and jobs legitimately work
 *                            across tenants; they carry no request and no
 *                            credential to derive one from.
 *
 * Cross-tenant access is available, but has to be asked for, one query at a
 * time:
 *
 *     Invoice::acrossTenants()->count();
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // New records inherit the active tenant, so a caller cannot create a
        // row that its own queries would then be unable to see.
        static::creating(function (Model $model): void {
            if ($model->getAttribute('org_id') === null) {
                $tenantId = app(TenantResolver::class)->getOrganizationId();

                if ($tenantId !== null) {
                    $model->setAttribute('org_id', $tenantId);
                }
            }
        });
    }

    /**
     * This one query, across every tenant.
     *
     * For platform-level work that is genuinely cross-tenant - admin reporting,
     * reconciliation, scheduled maintenance. Every use is a deliberate decision
     * to read another tenant's data, so keep them few and obvious;
     * TenantBypassTest counts them.
     *
     * Only the query it is called on: a relation it eager loads or constrains
     * keeps its own model's scope unless that query says so too. It replaced a
     * callback that switched the scope off for the whole process while it ran,
     * so every relation, listener and service inside read across tenants as
     * well.
     *
     *     Invoice::acrossTenants()->where('org_id', $id)->count();
     *     $builder->acrossTenants();
     *
     * @param  Builder<static>  $query
     */
    public function scopeAcrossTenants(Builder $query): void
    {
        $query->withoutGlobalScope(TenantScope::class);
    }
}
