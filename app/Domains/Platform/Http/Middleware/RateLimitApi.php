<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Organization\Services\TenantResolver;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API rate limiting.
 *
 * Budgets are held per tenant and per cost band, not per user against one flat
 * ceiling. Two reasons:
 *
 *   A tenant is the unit that pays and the unit that can be noisy. Keying on
 *   the user let one customer open several sessions and multiply their share,
 *   while an integration authenticating with an API key had no user at all and
 *   fell back to an IP, which is trivially rotated.
 *
 *   Endpoints are not equal. /pipeline/submit signs an invoice and calls ZATCA;
 *   /health returns a constant. Sharing one budget means the cheap endpoint's
 *   traffic can exhaust the expensive one's, and the expensive one cannot be
 *   throttled without throttling everything.
 *
 * Unauthenticated traffic keeps its own, tighter budget: there is no tenant to
 * attribute it to, and it is the surface most likely to be abused.
 *
 * @see config/security.php for the per-band limits
 */
class RateLimitApi
{
    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly TenantResolver $tenant,
    ) {}

    public function handle(Request $request, Closure $next, ?int $maxAttempts = null): Response
    {
        $band = $this->band($request);
        $limit = $maxAttempts ?? $this->limitFor($band);
        $key = $this->key($request, $band);

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            return ApiResponse::error(
                'Too many requests',
                429,
                ['retry_after' => $this->limiter->availableIn($key)]
            );
        }

        $this->limiter->hit($key, self::DECAY_SECONDS);

        return $this->addHeaders(
            $next($request),
            $limit,
            $this->limiter->remaining($key, $limit)
        );
    }

    /**
     * Which cost band this request falls into.
     *
     * Matched on the route rather than the URI so a path parameter cannot
     * change the band.
     */
    private function band(Request $request): string
    {
        $route = $request->route()?->uri() ?? '';

        foreach ((array) config('security.rate_limits.bands', []) as $band => $patterns) {
            foreach ((array) $patterns as $pattern) {
                if (str_contains($route, $pattern)) {
                    return $band;
                }
            }
        }

        return 'default';
    }

    private function limitFor(string $band): int
    {
        return (int) config(
            "security.rate_limits.{$band}",
            config('security.rate_limits.default', 60)
        );
    }

    /**
     * The budget this request draws from.
     */
    private function key(Request $request, string $band): string
    {
        $tenantId = $this->tenant->getOrganizationId();

        if ($tenantId !== null) {
            return "rate:tenant:{$tenantId}:{$band}";
        }

        // No tenant: an authenticated console user, else anonymous traffic.
        $userId = auth()->id();

        return $userId !== null
            ? "rate:user:{$userId}:{$band}"
            : "rate:ip:{$request->ip()}:anonymous";
    }

    private function addHeaders(Response $response, int $limit, int $remaining): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));

        return $response;
    }
}
