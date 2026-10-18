<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Middleware;

use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\WorkingBranches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the branch chosen in the portal's switcher on the request, after
 * PortalTenant has resolved the organization, so the lists, the dashboard
 * and the reports read that branch (BranchScope). A choice that is no
 * longer allowed is let go of rather than honoured.
 */
class PortalBranch
{
    public function __construct(
        private readonly WorkingBranches $working,
        private readonly BranchScope $scope,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);
        $userId = $request->user()?->getAuthIdentifier();

        $this->scope->select(is_string($organizationId) && $userId !== null
            ? $this->working->current((string) $userId, $organizationId)
            : null);

        return $next($request);
    }
}
