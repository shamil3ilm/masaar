<?php

declare(strict_types=1);

namespace App\Domains\Organization\Jobs;

use App\Domains\Organization\Services\TenantResolver;
use Closure;

/**
 * Job middleware: the job runs as the one organization it is for
 * (TenantResolver::runAs()), held to its rows as a request is. A queued job
 * that declares no tenant is refused its scoped queries (TenantScope).
 *
 *     public function middleware(): array
 *     {
 *         return [new ActsFor($this->organizationId)];
 *     }
 */
final class ActsFor
{
    public function __construct(
        private readonly string $organizationId,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        return app(TenantResolver::class)->runAs($this->organizationId, fn (): mixed => $next($job));
    }
}
