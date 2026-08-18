<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Middleware;

use App\Domains\Auth\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the customer portal's tenant from the authenticated session.
 *
 * The governing rule is that tenant identity is derived from the credential,
 * never from request input. An `org_id` in the query string is treated only as
 * a *selection* among the organizations the user already belongs to; it is
 * validated against active membership and rejected outright otherwise, rather
 * than silently falling back — a silent fallback would hide enumeration.
 *
 * Downstream code must read the tenant from the request attribute this sets,
 * never from the query string.
 */
class PortalTenant
{
    public const ORG_ID = 'portal_org_id';

    private const SESSION_KEY = 'portal_org_id';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(401);
        }

        $requested = $request->query('org_id');

        if (! is_string($requested) || $requested === '') {
            $requested = $request->session()->get(self::SESSION_KEY);
        }

        if (is_string($requested) && $requested !== '') {
            if (! $user->belongsToOrganization($requested)) {
                Log::warning('Portal tenant access denied', [
                    'user_id' => $user->getAuthIdentifier(),
                    'requested_org_id' => $requested,
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                // Clear the stale selection so the user is not wedged on 403.
                $request->session()->forget(self::SESSION_KEY);

                abort(403, 'You do not have access to that organization.');
            }

            $request->session()->put(self::SESSION_KEY, $requested);
            $request->attributes->set(self::ORG_ID, $requested);

            return $next($request);
        }

        // Nothing selected yet: a single-membership user has no choice to make.
        $organizationIds = $user->activeOrganizations()->pluck('organizations.id');

        if ($organizationIds->count() === 1) {
            $only = (string) $organizationIds->first();
            $request->session()->put(self::SESSION_KEY, $only);
            $request->attributes->set(self::ORG_ID, $only);
        }

        return $next($request);
    }
}
