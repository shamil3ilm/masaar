<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class TrackImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $impersonatedById      = $payload->get('impersonated_by_id');
            $impersonationSessionId = $payload->get('impersonation_session_id');

            if ($impersonatedById !== null) {
                $request->attributes->set('impersonated_by_id', $impersonatedById);
                $request->attributes->set('impersonation_session_id', $impersonationSessionId);
            }
        } catch (\Throwable) {
            // No token or invalid token — not an impersonation request, skip silently
        }

        return $next($request);
    }
}
