<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Licensing\PlatformLicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to validate platform license on API requests.
 *
 * This ensures the platform deployment is properly licensed.
 * Invalid or expired licenses will block API access.
 */
class ValidatePlatformLicense
{
    public function __construct(
        private PlatformLicenseService $licenseService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip license check if disabled (for development)
        if (!config('platform-license.enabled', true)) {
            return $next($request);
        }

        // Skip for certain paths (health check, etc.)
        $excludedPaths = config('platform-license.excluded_paths', [
            'api/health',
            'api/license/status',
        ]);

        foreach ($excludedPaths as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        $validation = $this->licenseService->validate();

        if (!$validation['valid']) {
            return response()->json([
                'error' => 'license_invalid',
                'message' => $validation['message'],
                'support' => 'Contact sales@masaar.com for licensing inquiries.',
            ], 403);
        }

        // Add license info to request for use in controllers
        $request->attributes->set('platform_license', $validation);

        // Log warning if license expiring soon
        if ($validation['days_remaining'] !== null && $validation['days_remaining'] <= 7) {
            $response = $next($request);

            // Add warning header for expiring license
            $response->headers->set(
                'X-License-Warning',
                "License expires in {$validation['days_remaining']} days"
            );

            return $response;
        }

        return $next($request);
    }
}
