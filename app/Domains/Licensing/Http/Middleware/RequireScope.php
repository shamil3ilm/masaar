<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Middleware;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use Closure;
use Illuminate\Http\Request;

/**
 * Require Scope Middleware.
 *
 * Checks that the license has the required scope(s).
 * Must be used after ValidateLicense middleware.
 *
 * Usage:
 *   Route::middleware(['license', 'scope:invoice.submit'])
 *   Route::middleware(['license', 'scope:invoice.submit,invoice.read'])
 *
 * ZATCA Compliance:
 * - Scopes only affect authorization, never data mutation
 * - Blocking only prevents new operations
 */
class RequireScope
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string ...$scopes Required scopes (comma-separated or multiple args)
     */
    public function handle(Request $request, Closure $next, string ...$scopes): mixed
    {
        $license = $request->attributes->get('license');

        if (!$license instanceof License) {
            throw new \RuntimeException('License not found in request. Ensure ValidateLicense middleware runs first.');
        }

        // Flatten scopes (handle both "scope1,scope2" and "scope1", "scope2")
        $requiredScopes = [];
        foreach ($scopes as $scope) {
            $requiredScopes = array_merge($requiredScopes, explode(',', $scope));
        }

        // Check all required scopes
        foreach ($requiredScopes as $scope) {
            $scope = trim($scope);
            if (!$license->hasScope($scope)) {
                throw LicenseException::scopeDenied($scope);
            }
        }

        return $next($request);
    }
}
