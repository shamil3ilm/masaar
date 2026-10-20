<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Domains\Auth\Models\User;
use Illuminate\Http\Request;

/**
 * Where "Back to the dashboard" goes on an error page: the area the person
 * was in when they met it, if they may be there, and otherwise the one that
 * is theirs - the console for a platform admin, the portal for everyone
 * else. Nothing for a visitor who is not signed in.
 *
 * A 404 for an address no route matches is drawn without a session, so it
 * reads as signed out and offers no dashboard; one from inside a page - a
 * record that is not there, a link to something removed - has the session.
 */
final class DashboardLink
{
    public static function for(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $console = $user->isPlatformAdmin();

        if ($request->is('admin', 'admin/*') && $console) {
            return route('admin.dashboard');
        }

        if ($request->is('portal', 'portal/*') || ! $console) {
            return route('portal.dashboard');
        }

        return route('admin.dashboard');
    }
}
