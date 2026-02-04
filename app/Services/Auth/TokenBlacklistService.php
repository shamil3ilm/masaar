<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class TokenBlacklistService
{
    private const CACHE_PREFIX = 'jwt_blacklist:';
    private const CACHE_TTL = 86400; // 24 hours (should match JWT TTL)

    /**
     * Blacklist the current token
     */
    public function blacklistCurrentToken(string $reason = 'logout'): void
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $jti = $payload->get('jti');
            $exp = $payload->get('exp');
            $userId = $payload->get('sub');

            $this->blacklistToken($jti, $userId, $reason, $exp);
        } catch (\Exception $e) {
            // Token already invalid or malformed, nothing to blacklist
        }
    }

    /**
     * Blacklist a specific token
     */
    public function blacklistToken(string $jti, ?int $userId, string $reason, int $expiresAt): void
    {
        // Add to cache for fast lookup
        $ttl = max(0, $expiresAt - time());
        Cache::put(self::CACHE_PREFIX . $jti, true, $ttl);

        // Also persist to database for durability (and cleanup)
        DB::table('token_blacklist')->insert([
            'jti' => $jti,
            'user_id' => $userId,
            'reason' => $reason,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'created_at' => now(),
        ]);
    }

    /**
     * Check if a token is blacklisted
     */
    public function isBlacklisted(string $jti): bool
    {
        // Check cache first
        if (Cache::has(self::CACHE_PREFIX . $jti)) {
            return true;
        }

        // Check database (for cache misses after restart)
        $exists = DB::table('token_blacklist')
            ->where('jti', $jti)
            ->where('expires_at', '>', now())
            ->exists();

        if ($exists) {
            // Repopulate cache
            Cache::put(self::CACHE_PREFIX . $jti, true, self::CACHE_TTL);
        }

        return $exists;
    }

    /**
     * Blacklist all tokens for a user (password change, account deletion, etc.)
     */
    public function blacklistAllUserTokens(int $userId, string $reason = 'password_change'): void
    {
        // Record the password change timestamp
        DB::table('password_changes')->insert([
            'user_id' => $userId,
            'changed_at' => now(),
            'ip_address' => request()->ip(),
        ]);

        // We can't invalidate tokens we don't know about, but we can check
        // the password_changes table when validating tokens
    }

    /**
     * Check if token was issued before a password change
     */
    public function wasIssuedBeforePasswordChange(int $userId, int $issuedAt): bool
    {
        return DB::table('password_changes')
            ->where('user_id', $userId)
            ->where('changed_at', '>', date('Y-m-d H:i:s', $issuedAt))
            ->exists();
    }

    /**
     * Cleanup expired blacklist entries (run via scheduler)
     */
    public function cleanupExpired(): int
    {
        return DB::table('token_blacklist')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
