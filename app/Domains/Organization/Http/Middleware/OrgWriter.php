<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A viewer reads an organization and changes nothing in it.
 *
 * Reads pass; anything else is refused when the caller's role in the
 * organization is viewer - an accountant or auditor shown the books. The
 * role is read from the membership as it stands, not from a token minted
 * before it changed.
 */
class OrgWriter
{
    private const READS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::READS, true)) {
            return $next($request);
        }

        $organizationId = $request->attributes->get(PortalTenant::ORG_ID) ?? $this->tenant->getOrganizationId();

        if (is_string($organizationId) && $request->user()?->roleIn($organizationId) === 'viewer') {
            $message = 'Your role in this organization is read-only.';

            return $request->expectsJson() ? ApiResponse::forbidden($message) : abort(403, $message);
        }

        return $next($request);
    }
}
