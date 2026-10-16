<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One permission, for one admin route: admin.can:licences.manage.
 *
 * After platform.admin, which says the account is an admin at all; this says
 * whether its role covers the action.
 */
class AdminCan
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        if ($user === null || ! $user->canAdmin($permission)) {
            Log::warning('Admin permission denied', [
                'user_id' => $user?->getAuthIdentifier(),
                'permission' => $permission,
                'path' => $request->path(),
            ]);

            abort(403, 'Your admin role does not allow this.');
        }

        return $next($request);
    }
}
