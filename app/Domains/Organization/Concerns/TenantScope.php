<?php

declare(strict_types=1);

namespace App\Domains\Organization\Concerns;

use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The query scope applied by BelongsToTenant.
 *
 * @see BelongsToTenant for the rules this implements and why.
 */
class TenantScope implements Scope
{
    private static bool $disabled = false;

    public function apply(Builder $builder, Model $model): void
    {
        if (self::$disabled) {
            return;
        }

        // Console and queue workers carry no credential to derive a tenant
        // from, and legitimately operate across all of them.
        if (app()->runningInConsole()) {
            return;
        }

        // A null tenant matches no rows, so a request that lost its tenant
        // context returns nothing rather than everything.
        $builder->where(
            $model->getTable().'.organization_id',
            app(TenantResolver::class)->getOrganizationId()
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutScope(callable $callback): mixed
    {
        $previous = self::$disabled;
        self::$disabled = true;

        try {
            return $callback();
        } finally {
            self::$disabled = $previous;
        }
    }
}
