<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Middleware;

use App\Domains\Licensing\DTOs\Credential;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\UsageEvent;
use App\Domains\Licensing\Services\ApiKeys;
use App\Domains\Licensing\Services\LicenseValidationService;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
use App\Http\Responses\ApiResponse;
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
        private readonly ApiKeys $apiKeys,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  string|null  $requiredScope  Required scope (e.g., 'invoice.submit')
     * @param  string|null  $requiredEnvironment  Required environment (e.g., 'production')
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
        $credential = null;

        try {
            // Extract credentials from request
            $apiKey = $this->extractApiKey($request);
            $apiSecret = $this->extractApiSecret($request);

            if (! $apiKey || ! $apiSecret) {
                throw LicenseException::invalidApiKey();
            }

            // The licence, or one of the organization's own API keys and
            // the licence it acts under.
            $credential = $this->validationService->authenticate($apiKey, $apiSecret, $request->ip());
            $license = $credential->licence;

            // Check environment match
            if ($requiredEnvironment !== null) {
                $this->validateEnvironment($credential, $requiredEnvironment);
            }

            // Check required scope if specified
            if ($requiredScope !== null) {
                $this->validateScopeAccess($credential, $requiredScope);
            }

            // Check rate limits
            $this->meteringService->checkRateLimit($license);

            // Establish the tenant through the resolver, which is what the
            // BelongsToTenant scope reads. A request attribute alone would
            // authenticate the licence without scoping its queries.
            $this->tenantResolver->setContext(
                OrganizationContext::forMachine((string) $license->org_id)
            );

            // The licence itself is carried on the request because the scope,
            // quota and environment middleware each need the model.
            //
            // The tenant is deliberately not: it is on the resolver above, and
            // two places holding the same fact is how they come to disagree.
            // license_id and request_id were set here and read nowhere —
            // callers take the id off the licence, and the request id off the
            // X-Request-ID header.
            $request->attributes->set('license', $license);

            // An organization's own key beside it: RequireScope narrows to
            // its scopes, and SandboxGuard reads its environment.
            if ($credential->key !== null) {
                $request->attributes->set(ApiKey::ATTRIBUTE, $credential->key);
            }

            // Process request
            $response = $next($request);

            // Calculate duration
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Track successful API call (both aggregate and event)
            $this->meteringService->recordApiCall($license, false);
            $this->recordUsageEvent($credential, $request, $requestId, 'success', $durationMs);
            $this->recordKeyUse($credential, method_exists($response, 'getStatusCode') && $response->getStatusCode() >= 400);

            // Add rate limit headers
            $this->addRateLimitHeaders($response, $credential);

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
            if ($credential !== null) {
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $this->recordUsageEvent($credential, $request, $requestId, 'failed', $durationMs, $e->errorCode);
                $this->meteringService->recordApiCall($credential->licence, true);
                $this->recordKeyUse($credential, true);
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
            if ($credential !== null) {
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $this->recordUsageEvent($credential, $request, $requestId, 'error', $durationMs, 'SYSTEM_ERROR');
                $this->meteringService->recordApiCall($credential->licence, true);
                $this->recordKeyUse($credential, true);
            }

            return ApiResponse::error(
                'License validation system error',
                500,
                [],
                'LICENSE_SYSTEM_ERROR',
                'licensing',
            );
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
    private function validateScopeAccess(Credential $credential, string $scope): void
    {
        if (! $credential->licence->hasScope($scope) || ($credential->key !== null && ! $credential->key->hasScope($scope))) {
            throw LicenseException::scopeDenied($scope);
        }
    }

    /**
     * Validate environment match.
     *
     * ZATCA Compliance: Prevents sandbox keys from submitting real invoices.
     */
    private function validateEnvironment(Credential $credential, string $requiredEnvironment): void
    {
        if ($credential->environment()->value !== $requiredEnvironment) {
            throw LicenseException::environmentMismatch(
                $credential->environment()->value,
                $requiredEnvironment
            );
        }
    }

    /**
     * Count the call against the organization's own key, when it was one.
     * Never at the request's expense.
     */
    private function recordKeyUse(Credential $credential, bool $failed): void
    {
        if ($credential->key === null) {
            return;
        }

        try {
            $this->apiKeys->recordUse($credential->key, $failed);
        } catch (\Throwable $e) {
            Log::warning('Failed to record API key use', [
                'api_key_id' => $credential->key->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a usage event (append-only for billing and audit).
     */
    private function recordUsageEvent(
        Credential $credential,
        Request $request,
        string $requestId,
        string $status,
        int $durationMs,
        ?string $errorCode = null
    ): void {
        $license = $credential->licence;

        try {
            UsageEvent::record($license->id, 'api.request', [
                'api_key_id' => $credential->key?->id,
                'request_id' => $requestId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => $status,
                'duration_ms' => $durationMs,
                'error_code' => $errorCode,
                'metadata' => [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'environment' => $credential->environment()->value,
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
     * Add rate limit headers to response.
     */
    private function addRateLimitHeaders(mixed $response, Credential $credential): void
    {
        if (! method_exists($response, 'header')) {
            return;
        }

        $license = $credential->licence;

        $response->header('X-RateLimit-Limit-Minute', $license->calls_per_min);
        $response->header('X-RateLimit-Limit-Day', $license->calls_per_day);
        $response->header('X-License-Tier', $license->tier->value);
        $response->header('X-License-Environment', $credential->environment()->value);
    }

    /**
     * Handle license exception and return appropriate response.
     */
    private function handleLicenseError(LicenseException $e): JsonResponse
    {
        // ApiResponse sets Retry-After itself for a retryable status, so
        // the header this used to add by hand for LICENSE_RATE_LIMITED now
        // comes from the same place as every other one.
        return ApiResponse::error(
            $e->getMessage(),
            $e->getCode() ?: 403,
            $e->context ?? [],
            $e->errorCode,
            'licensing',
            $e->context['retry_after'] ?? null,
        );
    }
}
