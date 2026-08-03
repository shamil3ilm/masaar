<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the Prometheus metrics endpoint.
 *
 * Admits a request whose source IP is allowlisted or that carries the metrics
 * bearer token. When neither control is configured the endpoint is closed in
 * production and open elsewhere, so a deployment that forgets to configure it
 * fails closed rather than publishing telemetry.
 *
 * @see config/metrics.php
 */
class RestrictMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isAuthorized($request)) {
            return $next($request);
        }

        Log::warning('Metrics access denied', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ApiResponse::forbidden('Metrics access denied');
    }

    private function isAuthorized(Request $request): bool
    {
        $allowedIps = config('metrics.allowed_ips', []);
        $token = config('metrics.token');

        if ($allowedIps === [] && ($token === null || $token === '')) {
            return ! app()->environment('production');
        }

        if (in_array($request->ip(), $allowedIps, true)) {
            return true;
        }

        return $token !== null
            && $token !== ''
            && hash_equals((string) $token, (string) $request->bearerToken());
    }
}
