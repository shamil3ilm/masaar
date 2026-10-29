<?php

declare(strict_types=1);

namespace App\Domains\Organization\Exceptions;

use LogicException;

/**
 * A command or job made a tenant-scoped query without saying whose data it
 * reads. A mistake in the code, never in the data: the query is refused
 * rather than run across every organization (TenantScope).
 */
final class TenantNotDeclaredException extends LogicException
{
    public static function for(string $model, string $work): self
    {
        return new self(sprintf(
            '%s queried %s with no tenant declared. Act for one organization inside TenantResolver::runAs(), '
            .'read across tenants one query at a time with acrossTenants(), or mark the class #[RunsAcrossTenants] with the reason.',
            $work,
            $model,
        ));
    }
}
