<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Middleware;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\UsageMeteringService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Invoice Quota Middleware.
 *
 * Checks monthly invoice quota before allowing invoice submission.
 * Should be used after ValidateLicense middleware.
 */
class CheckInvoiceQuota
{
    public function __construct(
        private readonly UsageMeteringService $meteringService,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $license = $request->attributes->get('license');

            if (! $license instanceof License) {
                throw new \RuntimeException('License not found in request. Ensure ValidateLicense middleware runs first.');
            }

            // Check invoice quota
            $this->meteringService->checkInvoiceQuota($license);

            return $next($request);
        } catch (LicenseException $e) {
            Log::warning('Invoice quota check failed', [
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
                'license_id' => $license?->id ?? null,
            ]);

            return $this->handleQuotaError($e);
        }
    }

    /**
     * Handle quota exception.
     */
    private function handleQuotaError(LicenseException $e): JsonResponse
    {
        return response()->json([
            'error' => true,
            'error_code' => $e->errorCode,
            'message' => $e->getMessage(),
            'context' => $e->context,
        ], 429);
    }
}
