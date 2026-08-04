<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Middleware;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\UsageEvent;
use App\Domains\Licensing\Services\LicenseValidationService;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * License Validation Middleware.
 *
 * Validates API key and secret on each request, enforces rate limits,
 * checks scopes, validates environment, and tracks usage metrics.
 *
 * ZATCA Compliance:
 * - Blocking only affects new operations, never existing data
 * - Environment validation prevents cross-environment leakage
 * - Scope checking is pure authorization (no data mutation)
 */
class ValidateLicense
{
    public function __construct(
        private readonly LicenseValidationService $validationService,
        private readonly UsageMeteringService $meteringService,
        private readonly TenantResolver $tenantResolver,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string|null $requiredScope Required scope (e.g., 'invoice.submit')
     * @param string|null $requiredEnvironment Required environment (e.g., 'production')
     */
    public function handle(
        Request $request,
        Closure $next,
        ?string $requiredScope = null,
        ?string $requiredEnvironment = null
    ): mixed {
        $startTime = microtime(true);
        $isError = false;
        $requestId = $request->header('X-Request-ID') ?? Str::uuid()->toString();
        $license = null;

        try {
            // Extract credentials from request
            $apiKey = $this->extractApiKey($request);
            $apiSecret = $this->extractApiSecret($request);

            if (!$apiKey || !$apiSecret) {
                throw LicenseException::invalidApiKey();
            }

            // Validate license
            $license = $this->validationService->validateAndGetLicense($apiKey, $apiSecret);

            // Check environment match
            if ($requiredEnvironment !== null) {
                $this->validateEnvironment($license, $requiredEnvironment);
            }

            // Check required scope if specified
            if ($requiredScope !== null) {
                $this->validateScopeAccess($license, $requiredScope);
            }

            // Check rate limits
            $this->meteringService->checkRateLimit($license);

            // Establish the tenant through the resolver, which is what the
            // BelongsToTenant scope reads. A request attribute alone would
            // authenticate the licence without scoping its queries.
            $this->tenantResolver->setContext(
                OrganizationContext::forMachine((string) $license->organization_id)
            );

            // Bind license to request for downstream use
            $request->attributes->set('license', $license);
            $request->attributes->set('license_id', $license->id);
            $request->attributes->set('organization_id', $license->organization_id);
            $request->attributes->set('request_id', $requestId);

            // Process request
            $response = $next($request);

            // Calculate duration
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Track successful API call (both aggregate and event)
            $this->meteringService->recordApiCall($license, false);
            $this->recordUsageEvent($license, $request, $requestId, 'success', $durationMs);

            // Add rate limit headers
            $this->addRateLimitHeaders($response, $license);

            return $response;
        } catch (LicenseException $e) {
            $isError = true;

            Log::warning('License validation failed', [
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
                'ip' => $request->ip(),
                'path' => $request->path(),
                'request_id' => $requestId,
            ]);

            // Record failed event if we have a license
            if ($license) {
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $this->recordUsageEvent($license, $request, $requestId, 'failed', $durationMs, $e->errorCode);
                $this->meteringService->recordApiCall($license, true);
            }

            return $this->handleLicenseError($e);
        } catch (\Exception $e) {
            $isError = true;

            Log::error('Unexpected license middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
            ]);

            // Record error event if we have a license
            if ($license) {
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $this->recordUsageEvent($license, $request, $requestId, 'error', $durationMs, 'SYSTEM_ERROR');
                $this->meteringService->recordApiCall($license, true);
            }

            return response()->json([
                'error' => true,
                'error_code' => 'LICENSE_SYSTEM_ERROR',
                'message' => 'License validation system error',
            ], 500);
        }
    }

    /**
     * Extract API key from request.
     *
     * Headers only. Credentials must never be read from the query string:
     * URLs are recorded by web servers, reverse proxies, CDNs, APM traces,
     * browser history and the Referer header, so a query-string credential
     * leaks into systems with far weaker access controls than the credential
     * store — and here it would leak the key and secret together.
     */
    private function extractApiKey(Request $request): ?string
    {
        // Try X-API-Key header first
        $apiKey = $request->header('X-API-Key');

        if ($apiKey) {
            return $apiKey;
        }

        // Try Authorization header with Bearer scheme
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            // Bearer token format: api_key:api_secret (base64 encoded)
            $token = substr($authHeader, 7);
            $decoded = base64_decode($token, true);
            if ($decoded && str_contains($decoded, ':')) {
                return explode(':', $decoded, 2)[0];
            }
        }

        return null;
    }

    /**
     * Extract API secret from request.
     *
     * Headers only — see extractApiKey().
     */
    private function extractApiSecret(Request $request): ?string
    {
        // Try X-API-Secret header first
        $apiSecret = $request->header('X-API-Secret');

        if ($apiSecret) {
            return $apiSecret;
        }

        // Try Authorization header with Bearer scheme
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $decoded = base64_decode($token, true);
            if ($decoded && str_contains($decoded, ':')) {
                $parts = explode(':', $decoded, 2);
                return $parts[1] ?? null;
            }
        }

        return null;
    }

    /**
     * Validate scope access for the license.
     *
     * ZATCA Compliance: Scopes only control authorization, never data access.
     */
    private function validateScopeAccess(License $license, string $scope): void
    {
        if (!$license->hasScope($scope)) {
            throw LicenseException::scopeDenied($scope);
        }
    }

    /**
     * Validate environment match.
     *
     * ZATCA Compliance: Prevents sandbox keys from submitting real invoices.
     */
    private function validateEnvironment(License $license, string $requiredEnvironment): void
    {
        if (!$license->matchesEnvironment($requiredEnvironment)) {
            throw LicenseException::environmentMismatch(
                $license->environment->value,
                $requiredEnvironment
            );
        }
    }

    /**
     * Record a usage event (append-only for billing and audit).
     */
    private function recordUsageEvent(
        License $license,
        Request $request,
        string $requestId,
        string $status,
        int $durationMs,
        ?string $errorCode = null
    ): void {
        try {
            UsageEvent::record($license->id, 'api.request', [
                'request_id' => $requestId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => $status,
                'duration_ms' => $durationMs,
                'error_code' => $errorCode,
                'metadata' => [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'environment' => $license->environment->value,
                ],
            ]);
        } catch (\Exception $e) {
            // Don't fail the request if event recording fails
            Log::warning('Failed to record usage event', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate feature access for the license.
     */
    private function validateFeatureAccess(License $license, string $feature): void
    {
        if (!$license->hasFeature($feature)) {
            throw LicenseException::featureNotAvailable($feature);
        }
    }

    /**
     * Add rate limit headers to response.
     */
    private function addRateLimitHeaders(mixed $response, License $license): void
    {
        if (!method_exists($response, 'header')) {
            return;
        }

        $response->header('X-RateLimit-Limit-Minute', $license->max_api_calls_per_minute);
        $response->header('X-RateLimit-Limit-Day', $license->max_api_calls_per_day);
        $response->header('X-License-Tier', $license->tier->value);
        $response->header('X-License-Environment', $license->environment->value);
    }

    /**
     * Handle license exception and return appropriate response.
     */
    private function handleLicenseError(LicenseException $e): JsonResponse
    {
        $response = [
            'error' => true,
            'error_code' => $e->errorCode,
            'message' => $e->getMessage(),
        ];

        if (!empty($e->context)) {
            $response['context'] = $e->context;
        }

        $httpStatus = $e->getCode() ?: 403;

        $jsonResponse = response()->json($response, $httpStatus);

        // Add Retry-After header for rate limiting
        if ($e->errorCode === 'LICENSE_RATE_LIMITED' && isset($e->context['retry_after'])) {
            $jsonResponse->header('Retry-After', $e->context['retry_after']);
        }

        return $jsonResponse;
    }
}
