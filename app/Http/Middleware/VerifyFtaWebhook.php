<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify UAE FTA webhook HMAC-SHA256 signature.
 *
 * FTA signs the raw payload with the shared secret and sends it in
 * the X-FTA-Signature header. Reject replays older than 5 minutes.
 */
class VerifyFtaWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret    = config('fta.webhook_secret');
        $signature = $request->header('X-FTA-Signature');
        $timestamp = $request->header('X-FTA-Timestamp');

        if (! $secret || ! $signature || ! $timestamp) {
            return response()->json(['error' => 'Missing UAE FTA webhook headers'], 401);
        }

        // Replay protection: reject if older than 5 minutes
        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return response()->json(['error' => 'UAE FTA webhook timestamp expired'], 401);
        }

        $expectedSig = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (! hash_equals($expectedSig, $signature)) {
            return response()->json(['error' => 'Invalid UAE FTA webhook signature'], 401);
        }

        return $next($request);
    }
}
