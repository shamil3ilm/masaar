<?php

declare(strict_types=1);

namespace App\Domains\Organization\Concerns;

use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Confines a model's queries to the current tenant.
 *
 * Tenant isolation is the platform's most important invariant: one taxpayer
 * must never see another's invoices. Enforcing it by writing
 * `where('organization_id', ...)` in every query works only for as long as
 * every future author remembers, and nothing fails when one does not. This
 * makes the default safe and the exception explicit.
 *
 * Three situations, deliberately different:
 *
 *   Tenant context present   scope to that organization.
 *   No context, in HTTP      scope to a value that matches nothing. An
 *                            authenticated request that somehow lost its
 *                            tenant returns empty rather than everything.
 *   Console and queue        no scope. Commands and jobs legitimately work
 *                            across tenants; they carry no request and no
 *                            credential to derive one from.
 *
 * Cross-tenant access inside a request is available, but has to be asked for:
 *
 *     Invoice::withoutTenantScope(fn () => Invoice::count());
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        // New records inherit the active tenant, so a caller cannot create a
        // row that its own queries would then be unable to see.
        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id') === null) {
                $tenantId = app(TenantResolver::class)->getOrganizationId();

                if ($tenantId !== null) {
                    $model->setAttribute('organization_id', $tenantId);
                }
            }
        });
    }

    /**
     * Run a callback with tenant scoping suspended.
     *
     * For platform-level work that is genuinely cross-tenant — admin reporting,
     * reconciliation, scheduled maintenance. Every use is a deliberate decision
     * to read another tenant's data, so keep them few and obvious.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutTenantScope(callable $callback): mixed
    {
        return TenantScope::withoutScope($callback);
    }
}
