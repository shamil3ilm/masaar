<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LoginAttemptService
{
    // Configurable thresholds
    private const MAX_ATTEMPTS_PER_EMAIL = 5;
    private const MAX_ATTEMPTS_PER_IP = 20;
    private const LOCKOUT_MINUTES = 15;
    private const ATTEMPT_WINDOW_MINUTES = 15;

    /**
     * Record a login attempt
     */
    public function recordAttempt(string $email, string $ipAddress, bool $successful): void
    {
        DB::table('login_attempts')->insert([
            'email' => strtolower($email),
            'ip_address' => $ipAddress,
            'successful' => $successful,
            'attempted_at' => now(),
        ]);

        // Update rate limit counters
        if (!$successful) {
            $this->incrementCounter("login_attempts:email:{$email}");
            $this->incrementCounter("login_attempts:ip:{$ipAddress}");
        } else {
            // Clear counters on successful login
            $this->clearCounters($email, $ipAddress);
        }
    }

    /**
     * Check if login is allowed (not rate limited)
     */
    public function isAllowed(string $email, string $ipAddress): array
    {
        $email = strtolower($email);

        // Check email-based limit
        $emailAttempts = $this->getAttemptCount("login_attempts:email:{$email}");
        if ($emailAttempts >= self::MAX_ATTEMPTS_PER_EMAIL) {
            $remainingSeconds = $this->getRemainingLockoutSeconds("login_attempts:email:{$email}");
            return [
                'allowed' => false,
                'reason' => 'TOO_MANY_ATTEMPTS',
                'message' => "Too many login attempts for this email. Please try again in {$this->formatTime($remainingSeconds)}.",
                'retry_after' => $remainingSeconds,
            ];
        }

        // Check IP-based limit
        $ipAttempts = $this->getAttemptCount("login_attempts:ip:{$ipAddress}");
        if ($ipAttempts >= self::MAX_ATTEMPTS_PER_IP) {
            $remainingSeconds = $this->getRemainingLockoutSeconds("login_attempts:ip:{$ipAddress}");
            return [
                'allowed' => false,
                'reason' => 'TOO_MANY_ATTEMPTS_IP',
                'message' => "Too many login attempts from this IP. Please try again in {$this->formatTime($remainingSeconds)}.",
                'retry_after' => $remainingSeconds,
            ];
        }

        return [
            'allowed' => true,
            'remaining_attempts' => min(
                self::MAX_ATTEMPTS_PER_EMAIL - $emailAttempts,
                self::MAX_ATTEMPTS_PER_IP - $ipAttempts
            ),
        ];
    }

    /**
     * Get recent attempts for an email (for security logging)
     */
    public function getRecentAttempts(string $email, int $hours = 24): array
    {
        return DB::table('login_attempts')
            ->where('email', strtolower($email))
            ->where('attempted_at', '>', now()->subHours($hours))
            ->orderByDesc('attempted_at')
            ->limit(50)
            ->get()
            ->toArray();
    }

    /**
     * Cleanup old attempts (run via scheduler)
     */
    public function cleanupOldAttempts(int $daysToKeep = 7): int
    {
        return DB::table('login_attempts')
            ->where('attempted_at', '<', now()->subDays($daysToKeep))
            ->delete();
    }

    private function incrementCounter(string $key): void
    {
        $current = Cache::get($key, 0);
        Cache::put($key, $current + 1, now()->addMinutes(self::LOCKOUT_MINUTES));
    }

    private function getAttemptCount(string $key): int
    {
        return Cache::get($key, 0);
    }

    private function getRemainingLockoutSeconds(string $key): int
    {
        $ttl = Cache::getStore()->ttl($key);
        return max(0, $ttl ?? self::LOCKOUT_MINUTES * 60);
    }

    private function clearCounters(string $email, string $ipAddress): void
    {
        Cache::forget("login_attempts:email:{$email}");
        Cache::forget("login_attempts:ip:{$ipAddress}");
    }

    private function formatTime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        $minutes = ceil($seconds / 60);
        return "{$minutes} minute" . ($minutes > 1 ? 's' : '');
    }
}
