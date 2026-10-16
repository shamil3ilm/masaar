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

        // No organization to read a role in is a refusal, not a pass.
        if (! is_string($organizationId)) {
            return $this->refuse($request, 'Choose an organization first.');
        }

        if ($request->user()?->roleIn($organizationId) === 'viewer') {
            return $this->refuse($request, 'Your role in this organization is read-only.');
        }

        return $next($request);
    }

    private function refuse(Request $request, string $message): Response
    {
        return $request->expectsJson() ? ApiResponse::forbidden($message) : abort(403, $message);
    }
}
