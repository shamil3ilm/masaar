<?php

declare(strict_types=1);

namespace App\Domains\Organization\Concerns;

use App\Domains\Organization\Exceptions\TenantNotDeclaredException;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The query scope applied by BelongsToTenant.
 *
 * It keeps no state of its own. A query that reads across tenants removes it
 * from itself - acrossTenants(), which is withoutGlobalScope(TenantScope::class)
 * - and no other query in the process is affected.
 *
 * @see BelongsToTenant for the rules this implements and why.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantResolver::class)->getOrganizationId();

        if ($tenantId !== null) {
            $builder->where($model->getTable().'.org_id', $tenantId);

            return;
        }

        // A command or worker that has declared which tenant it is acting for
        // - TenantResolver::runAs() - is held to it, the same as a request,
        // above. One that declared neither that nor #[RunsAcrossTenants] is
        // refused: a forgotten declaration is an error, not a read across
        // every organization. Console outside a command or job of the
        // application's own - a seeder, tinker - stays unscoped.
        if (app()->runningInConsole()) {
            $work = app(TenantResolver::class)->undeclaredWork();

            if ($work !== null) {
                throw TenantNotDeclaredException::for($model::class, $work);
            }

            return;
        }

        // A request that lost its tenant context matches nothing. Not
        // where('org_id', null): that is org_id IS NULL, which returns every
        // row a tenant table holds without an organization.
        $builder->whereRaw('1 = 0');
    }
}
