<?php

namespace App\Domains\Auth\Http\Middleware;

use App\Domains\Auth\Models\ApiKey;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Key authentication middleware.
 *
 * Authenticates requests using API keys for server-to-server integration.
 * API key should be passed in the X-API-Key header.
 */
class ApiKeyAuth
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
    ) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (empty($apiKey)) {
            return ApiResponse::unauthorized('API key required');
        }

        $key = ApiKey::findByKey($apiKey);

        if (! $key) {
            return ApiResponse::unauthorized('Invalid API key');
        }

        if (! $key->isValid()) {
            return ApiResponse::unauthorized('API key expired or disabled');
        }

        // Check scope if specified
        if ($scope && ! $key->hasScope($scope)) {
            return ApiResponse::forbidden('API key lacks required scope: '.$scope);
        }

        // Record usage
        $key->recordUsage();

        // Establish the tenant through the resolver, which is what the
        // BelongsToTenant scope reads. Setting only a request attribute would
        // authenticate the caller without scoping their queries.
        $this->tenantResolver->setContext(
            OrganizationContext::forMachine($key->org_id)
        );

        $request->attributes->set('api_key', $key);
        $request->attributes->set('org_id', $key->org_id);

        return $next($request);
    }
}
