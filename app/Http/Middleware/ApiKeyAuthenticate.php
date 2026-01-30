<?php

namespace App\Http\Middleware;

use App\Domains\Auth\Models\ApiKey;
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
class ApiKeyAuthenticate
{
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
            return ApiResponse::forbidden('API key lacks required scope: ' . $scope);
        }

        // Record usage
        $key->recordUsage();

        // Store organization context for the request
        $request->attributes->set('api_key', $key);
        $request->attributes->set('organization_id', $key->organization_id);

        return $next($request);
    }
}
