<?php

namespace App\Domains\Auth\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts access to platform admin routes.
 *
 * A user is considered a platform admin when their JWT token carries
 * role=admin inside the organization context. This middleware must run
 * after jwt.auth so that the tenant context is already populated.
 */
class EnsureUserIsAdmin
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->tenantResolver->getContext();

        if ($context === null || ! $context->isAdmin()) {
            return ApiResponse::forbidden('Admin access required');
        }

        return $next($request);
    }
}
