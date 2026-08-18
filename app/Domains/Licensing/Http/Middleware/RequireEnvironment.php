<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Middleware;

use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use Closure;
use Illuminate\Http\Request;

/**
 * Require Environment Middleware.
 *
 * Ensures the license environment matches the required environment.
 * Must be used after ValidateLicense middleware.
 *
 * Usage:
 *   Route::middleware(['license', 'env:production'])
 *
 * ZATCA Compliance:
 * - Prevents sandbox keys from submitting real invoices to ZATCA
 * - Prevents cross-environment data leakage
 * - Audit-approved separation of concerns
 */
class RequireEnvironment
{
    /**
     * Handle an incoming request.
     *
     * @param  string  $environment  Required environment ('sandbox' or 'production')
     */
    public function handle(Request $request, Closure $next, string $environment): mixed
    {
        $license = $request->attributes->get('license');

        if (! $license instanceof License) {
            throw new \RuntimeException('License not found in request. Ensure ValidateLicense middleware runs first.');
        }

        // Validate environment enum
        $requiredEnv = LicenseEnvironment::tryFrom($environment);
        if (! $requiredEnv) {
            throw new \InvalidArgumentException("Invalid environment: {$environment}");
        }

        if ($license->environment !== $requiredEnv) {
            throw LicenseException::environmentMismatch(
                $license->environment->value,
                $requiredEnv->value
            );
        }

        return $next($request);
    }
}
