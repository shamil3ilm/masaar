<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Middleware;

use App\Domains\Licensing\Services\SandboxGuard;
use App\Domains\Organization\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;

/**
 * A sandbox API key is refused on a route that issues or files, where the
 * organization's country files with the live authority (SandboxGuard).
 *
 * Before any work, with the answer an integrator can act on: the same
 * AUTH_ENV_MISMATCH a licence's environment check gives. Must run after
 * ValidateLicense, which establishes the tenant and the key.
 */
class RefuseSandboxFiling
{
    public function __construct(
        private readonly SandboxGuard $guard,
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->guard->assertMayFile($this->tenant->getOrganization());

        return $next($request);
    }
}
