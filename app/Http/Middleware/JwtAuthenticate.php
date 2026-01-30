<?php

namespace App\Http\Middleware;

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
 * Validates JWT token and binds user to Laravel's auth context.
 */
class JwtAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return ApiResponse::unauthorized('User not found');
            }

            // Bind user to Laravel's auth context
            auth()->setUser($user);

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
