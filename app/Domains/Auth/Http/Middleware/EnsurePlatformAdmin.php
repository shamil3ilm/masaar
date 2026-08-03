<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the Masaar-internal admin console to platform administrators.
 *
 * Session-guard counterpart to EnsureUserIsAdmin, which reads the JWT tenant
 * context and answers in JSON. The privilege checked here is cross-tenant and
 * is NOT the per-organization `admin` pivot role — a customer's org-admin must
 * not reach this console.
 *
 * Must run after `auth`, which guarantees a user is present.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || ! $user->isPlatformAdmin()) {
            Log::warning('Platform admin access denied', [
                'user_id' => $user?->getAuthIdentifier(),
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            abort(403, 'Platform administrator access required.');
        }

        return $next($request);
    }
}
