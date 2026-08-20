<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts an action to whoever runs the organization.
 *
 * For the things a member should not be able to do alone: obtaining or
 * resetting the taxpayer's signing credentials, changing a branch's lifecycle,
 * and deleting records that have been filed. Everything those have in common
 * is that they are hard to undo and they affect the whole organization, rather
 * than being the day-to-day invoicing a member is there for.
 *
 * The role comes from the database, not from the token's role claim. The claim
 * is fixed when the token is issued, so reading it would leave a demoted admin
 * with the powers of one until their token expired — the same reason JwtGuard
 * re-checks membership rather than trusting org_id.
 */
class IsOrgAdmin
{
    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $organizationId = $this->tenant->getOrganizationId();

        if ($user === null || $organizationId === null) {
            return ApiResponse::unauthorized('Organization context is required.');
        }

        if ($user->roleIn($organizationId) !== 'admin') {
            return ApiResponse::forbidden(
                'This action is restricted to organization administrators.'
            );
        }

        return $next($request);
    }
}
