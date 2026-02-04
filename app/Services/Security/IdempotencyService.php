<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    private const CACHE_PREFIX = 'idempotency:';
    private const DEFAULT_TTL_HOURS = 24;

    /**
     * Check if request with this key was already processed
     * Returns cached response if exists, null otherwise
     */
    public function getExistingResponse(string $key, int $userId): ?JsonResponse
    {
        $cacheKey = $this->getCacheKey($key, $userId);

        // Check cache first
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $this->recreateResponse($cached);
        }

        // Check database
        $record = DB::table('idempotency_keys')
            ->where('key', $key)
            ->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->first();

        if ($record) {
            // Repopulate cache
            $data = [
                'response' => $record->response,
                'status_code' => $record->status_code,
            ];
            Cache::put($cacheKey, $data, now()->addHours(self::DEFAULT_TTL_HOURS));

            return $this->recreateResponse($data);
        }

        return null;
    }

    /**
     * Store a response for an idempotency key
     */
    public function storeResponse(
        string $key,
        int $userId,
        string $endpoint,
        JsonResponse $response
    ): void {
        $cacheKey = $this->getCacheKey($key, $userId);
        $expiresAt = now()->addHours(self::DEFAULT_TTL_HOURS);

        $data = [
            'response' => $response->getContent(),
            'status_code' => $response->getStatusCode(),
        ];

        // Store in cache
        Cache::put($cacheKey, $data, $expiresAt);

        // Store in database for durability
        DB::table('idempotency_keys')->updateOrInsert(
            ['key' => $key, 'user_id' => $userId],
            [
                'endpoint' => $endpoint,
                'response' => $response->getContent(),
                'status_code' => $response->getStatusCode(),
                'created_at' => now(),
                'expires_at' => $expiresAt,
            ]
        );
    }

    /**
     * Check if a key is currently being processed (for race conditions)
     */
    public function acquireLock(string $key, int $userId, int $timeoutSeconds = 30): bool
    {
        $lockKey = "idempotency_lock:{$key}:{$userId}";
        return Cache::add($lockKey, true, $timeoutSeconds);
    }

    /**
     * Release a processing lock
     */
    public function releaseLock(string $key, int $userId): void
    {
        $lockKey = "idempotency_lock:{$key}:{$userId}";
        Cache::forget($lockKey);
    }

    /**
     * Cleanup expired keys (run via scheduler)
     */
    public function cleanupExpired(): int
    {
        return DB::table('idempotency_keys')
            ->where('expires_at', '<', now())
            ->delete();
    }

    private function getCacheKey(string $key, int $userId): string
    {
        return self::CACHE_PREFIX . "{$userId}:{$key}";
    }

    private function recreateResponse(array $data): JsonResponse
    {
        $content = json_decode($data['response'], true);

        // Add header to indicate this is a replayed response
        return response()
            ->json($content, $data['status_code'])
            ->header('X-Idempotent-Replayed', 'true');
    }
}
