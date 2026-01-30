<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API rate limiting middleware.
 *
 * Limits: 60 requests per minute for authenticated users.
 * Uses user ID as key, falls back to IP for unauthenticated requests.
 */
class RateLimitApi
{
    public function __construct(
        private readonly RateLimiter $limiter
    ) {}

    public function handle(Request $request, Closure $next, int $maxAttempts = 60): Response
    {
        $key = $this->resolveKey($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return ApiResponse::error(
                'Too many requests',
                429,
                ['retry_after' => $this->limiter->availableIn($key)]
            );
        }

        $this->limiter->hit($key, 60); // 1 minute decay

        $response = $next($request);

        // Add rate limit headers
        return $this->addHeaders(
            $response,
            $maxAttempts,
            $this->limiter->remaining($key, $maxAttempts)
        );
    }

    /**
     * Generate unique key for rate limiting.
     */
    private function resolveKey(Request $request): string
    {
        $userId = auth()->id();

        if ($userId) {
            return 'rate_limit:user:' . $userId;
        }

        return 'rate_limit:ip:' . $request->ip();
    }

    /**
     * Add rate limit headers to response.
     */
    private function addHeaders(Response $response, int $maxAttempts, int $remaining): Response
    {
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $remaining));

        return $response;
    }
}
