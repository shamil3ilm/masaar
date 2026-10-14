<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a browser session whose account has been suspended.
 *
 * The console login refuses a suspended account, and that was the only check:
 * a session opened before the suspension went on working until it expired,
 * which for a remembered session is years. The guard reloads the user from
 * the database on every request, so the status read here is current.
 */
class ActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user === null || $user->isActive()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'This account has been suspended.');
    }
}
