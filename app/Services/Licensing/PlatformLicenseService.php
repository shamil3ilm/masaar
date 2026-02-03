<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Platform License Service.
 *
 * Validates platform deployment licenses for partners.
 * Supports both phone-home validation and offline fallback.
 */
class PlatformLicenseService
{
    /**
     * License types.
     */
    public const TYPE_TRIAL = 'TRIAL';
    public const TYPE_PRODUCTION = 'PROD';
    public const TYPE_DEVELOPMENT = 'DEV';

    /**
     * Secret key for signing license keys.
     * In production, this should be in a secure location.
     */
    private string $signingSecret;

    /**
     * License server URL for phone-home validation.
     */
    private ?string $licenseServerUrl;

    /**
     * Cache key for license validation result.
     */
    private const CACHE_KEY = 'platform_license_validation';

    /**
     * Cache duration in seconds (1 hour).
     */
    private const CACHE_DURATION = 3600;

    public function __construct()
    {
        $this->signingSecret = config('platform-license.signing_secret', 'complipay-default-secret-change-me');
        $this->licenseServerUrl = config('platform-license.server_url');
    }

    /**
     * Validate the platform license.
     *
     * @return array{valid: bool, message: string, type: ?string, partner: ?string, expires_at: ?string, days_remaining: ?int}
     */
    public function validate(): array
    {
        $licenseKey = config('platform-license.key');

        if (empty($licenseKey)) {
            return $this->invalidResult('No license key configured. Set PLATFORM_LICENSE_KEY in .env');
        }

        // Check cache first
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        // Try phone-home validation first (if server URL is configured)
        if ($this->licenseServerUrl) {
            $result = $this->validateRemote($licenseKey);
            if ($result !== null) {
                Cache::put(self::CACHE_KEY, $result, self::CACHE_DURATION);
                return $result;
            }
            // If remote validation fails, fall through to offline
            Log::warning('License phone-home failed, falling back to offline validation');
        }

        // Offline validation fallback
        $result = $this->validateOffline($licenseKey);
        Cache::put(self::CACHE_KEY, $result, self::CACHE_DURATION);

        return $result;
    }

    /**
     * Validate license via phone-home to license server.
     */
    private function validateRemote(string $licenseKey): ?array
    {
        try {
            $response = Http::timeout(5)
                ->retry(2, 100)
                ->post($this->licenseServerUrl . '/validate', [
                    'license_key' => $licenseKey,
                    'domain' => request()->getHost(),
                    'ip' => request()->server('SERVER_ADDR'),
                    'version' => config('app.version', '1.0.0'),
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['valid'] ?? false) {
                    return [
                        'valid' => true,
                        'message' => 'License validated via server',
                        'type' => $data['type'] ?? null,
                        'partner' => $data['partner'] ?? null,
                        'expires_at' => $data['expires_at'] ?? null,
                        'days_remaining' => $data['days_remaining'] ?? null,
                        'features' => $data['features'] ?? [],
                        'validation_method' => 'remote',
                    ];
                }

                return $this->invalidResult($data['message'] ?? 'License validation failed');
            }
        } catch (\Exception $e) {
            Log::warning('License server unreachable', [
                'error' => $e->getMessage(),
                'url' => $this->licenseServerUrl,
            ]);
        }

        return null; // Signal to fall back to offline validation
    }

    /**
     * Validate license offline using embedded data.
     *
     * License key format: {PARTNER}-{TYPE}-{EXPIRY_YYYYMMDD}-{SIGNATURE}
     * Example: TAXFLY-TRIAL-20260303-a1b2c3d4
     */
    private function validateOffline(string $licenseKey): array
    {
        $parts = explode('-', $licenseKey);

        if (count($parts) < 4) {
            return $this->invalidResult('Invalid license key format');
        }

        $partner = $parts[0];
        $type = $parts[1];
        $expiryDate = $parts[2];
        $signature = $parts[3];

        // Validate signature
        $expectedSignature = $this->generateSignature($partner, $type, $expiryDate);
        if (!hash_equals($expectedSignature, $signature)) {
            return $this->invalidResult('Invalid license key signature');
        }

        // Validate type
        if (!in_array($type, [self::TYPE_TRIAL, self::TYPE_PRODUCTION, self::TYPE_DEVELOPMENT])) {
            return $this->invalidResult('Invalid license type');
        }

        // Validate expiry
        try {
            $expiresAt = \DateTime::createFromFormat('Ymd', $expiryDate);
            if (!$expiresAt) {
                return $this->invalidResult('Invalid expiration date in license');
            }
            $expiresAt->setTime(23, 59, 59);
        } catch (\Exception $e) {
            return $this->invalidResult('Invalid expiration date format');
        }

        $now = new \DateTime();
        if ($now > $expiresAt) {
            $expiredDays = $now->diff($expiresAt)->days;
            return $this->invalidResult("License expired {$expiredDays} days ago. Contact sales@complipay.com to renew.");
        }

        $daysRemaining = (int) $now->diff($expiresAt)->days;

        return [
            'valid' => true,
            'message' => 'License valid',
            'type' => $type,
            'partner' => $partner,
            'expires_at' => $expiresAt->format('Y-m-d'),
            'days_remaining' => $daysRemaining,
            'features' => $this->getFeaturesForType($type),
            'validation_method' => 'offline',
        ];
    }

    /**
     * Generate a license key.
     *
     * @param string $partner Partner identifier (e.g., 'TAXFLY')
     * @param string $type License type (TRIAL, PROD, DEV)
     * @param \DateTime $expiresAt Expiration date
     * @return string The license key
     */
    public function generateKey(string $partner, string $type, \DateTime $expiresAt): string
    {
        $partner = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $partner));
        $type = strtoupper($type);
        $expiryDate = $expiresAt->format('Ymd');
        $signature = $this->generateSignature($partner, $type, $expiryDate);

        return "{$partner}-{$type}-{$expiryDate}-{$signature}";
    }

    /**
     * Generate signature for license key components.
     */
    private function generateSignature(string $partner, string $type, string $expiryDate): string
    {
        $data = "{$partner}-{$type}-{$expiryDate}";
        return substr(hash_hmac('sha256', $data, $this->signingSecret), 0, 8);
    }

    /**
     * Get features for license type.
     */
    private function getFeaturesForType(string $type): array
    {
        return match ($type) {
            self::TYPE_TRIAL => [
                'max_invoices_per_month' => 500,
                'max_organizations' => 5,
                'support' => 'email',
                'api_rate_limit' => 100,
            ],
            self::TYPE_PRODUCTION => [
                'max_invoices_per_month' => -1, // unlimited
                'max_organizations' => -1,
                'support' => 'priority',
                'api_rate_limit' => 10000,
            ],
            self::TYPE_DEVELOPMENT => [
                'max_invoices_per_month' => 100,
                'max_organizations' => 2,
                'support' => 'community',
                'api_rate_limit' => 50,
            ],
            default => [],
        };
    }

    /**
     * Create invalid result array.
     */
    private function invalidResult(string $message): array
    {
        return [
            'valid' => false,
            'message' => $message,
            'type' => null,
            'partner' => null,
            'expires_at' => null,
            'days_remaining' => null,
            'features' => [],
            'validation_method' => null,
        ];
    }

    /**
     * Clear cached validation result.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Check if license is valid (simple boolean check).
     */
    public function isValid(): bool
    {
        return $this->validate()['valid'];
    }

    /**
     * Get days remaining on license.
     */
    public function getDaysRemaining(): ?int
    {
        return $this->validate()['days_remaining'];
    }

    /**
     * Check if running in trial mode.
     */
    public function isTrial(): bool
    {
        $result = $this->validate();
        return $result['valid'] && $result['type'] === self::TYPE_TRIAL;
    }

    /**
     * Log license status for monitoring.
     */
    public function logStatus(): void
    {
        $result = $this->validate();

        $logData = [
            'valid' => $result['valid'],
            'type' => $result['type'],
            'partner' => $result['partner'],
            'days_remaining' => $result['days_remaining'],
            'validation_method' => $result['validation_method'] ?? 'unknown',
        ];

        if ($result['valid']) {
            if ($result['days_remaining'] !== null && $result['days_remaining'] <= 7) {
                Log::warning('Platform license expiring soon', $logData);
            } else {
                Log::info('Platform license valid', $logData);
            }
        } else {
            Log::error('Platform license invalid', array_merge($logData, [
                'message' => $result['message'],
            ]));
        }
    }
}
