<?php

namespace App\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

/**
 * JWT authentication middleware.
 *
 * Validates JWT token, binds user to Laravel's auth context,
 * and sets the tenant context so all downstream queries are
 * properly scoped to the authenticated organization.
 */
class JwtAuthenticate
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $token = JWTAuth::parseToken();
            $user = $token->authenticate();

            if (! $user) {
                return ApiResponse::unauthorized('User not found');
            }

            // Bind user to Laravel's auth context
            auth()->setUser($user);

            // Extract organization claims and set tenant context so all
            // downstream services and queries are scoped to the correct tenant.
            $claims = $token->getPayload()->toArray();

            if (isset($claims['org_id'], $claims['role'])) {
                $this->tenantResolver->setContext(
                    OrganizationContext::fromClaims($claims)
                );
            }

        } catch (TokenExpiredException $e) {
            return ApiResponse::unauthorized('Token expired');

        } catch (TokenInvalidException $e) {
            return ApiResponse::unauthorized('Token invalid');

        } catch (JWTException $e) {
            return ApiResponse::unauthorized('Token not provided');
        }

        return $next($request);
    }
}
