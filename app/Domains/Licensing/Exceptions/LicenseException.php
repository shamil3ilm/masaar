<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Exceptions;

use Exception;

/**
 * License-related exceptions.
 */
class LicenseException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $context = [],
        int $httpStatus = 403,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function invalidApiKey(): self
    {
        return new self(
            'Invalid API key',
            'LICENSE_INVALID_KEY',
            [],
            401
        );
    }

    public static function invalidApiSecret(): self
    {
        return new self(
            'Invalid API secret',
            'LICENSE_INVALID_SECRET',
            [],
            401
        );
    }

    public static function expired(?\DateTimeInterface $expiresAt): self
    {
        return new self(
            'License has expired',
            'LICENSE_EXPIRED',
            ['expired_at' => $expiresAt?->format('Y-m-d H:i:s')],
            403
        );
    }

    public static function suspended(?string $reason): self
    {
        return new self(
            'License is suspended' . ($reason ? ": {$reason}" : ''),
            'LICENSE_SUSPENDED',
            ['reason' => $reason],
            403
        );
    }

    public static function revoked(?string $reason): self
    {
        return new self(
            'License has been revoked' . ($reason ? ": {$reason}" : ''),
            'LICENSE_REVOKED',
            ['reason' => $reason],
            403
        );
    }

    public static function inactive(): self
    {
        return new self(
            'License is not active',
            'LICENSE_INACTIVE',
            [],
            403
        );
    }

    public static function pendingActivation(): self
    {
        return new self(
            'License is pending activation. Please complete the activation process.',
            'LICENSE_PENDING_ACTIVATION',
            [],
            403
        );
    }

    public static function quotaExceeded(string $limitType, int $limit, int $current): self
    {
        return new self(
            "Quota exceeded for {$limitType}: {$current}/{$limit}",
            'LICENSE_QUOTA_EXCEEDED',
            ['limit_type' => $limitType, 'limit' => $limit, 'current' => $current],
            429
        );
    }

    public static function rateLimited(string $limitType, int $limit, int $retryAfter): self
    {
        return new self(
            "Rate limit exceeded for {$limitType}. Retry after {$retryAfter} seconds.",
            'LICENSE_RATE_LIMITED',
            ['limit_type' => $limitType, 'limit' => $limit, 'retry_after' => $retryAfter],
            429
        );
    }

    public static function featureNotAvailable(string $feature): self
    {
        return new self(
            "Feature '{$feature}' is not available in your license tier",
            'LICENSE_FEATURE_UNAVAILABLE',
            ['feature' => $feature],
            403
        );
    }

    public static function organizationLimitReached(int $limit): self
    {
        return new self(
            "Organization limit reached: {$limit}. Upgrade your license to add more.",
            'LICENSE_ORG_LIMIT_REACHED',
            ['limit' => $limit],
            403
        );
    }

    /**
     * Scope not available for this API key.
     */
    public static function scopeDenied(string $scope): self
    {
        return new self(
            "Access denied. Required scope '{$scope}' is not available for this API key.",
            'AUTH_SCOPE_DENIED',
            ['required_scope' => $scope],
            403
        );
    }

    /**
     * Environment mismatch (e.g., sandbox key used in production).
     *
     * ZATCA Compliance: Prevents sandbox keys from submitting real invoices.
     */
    public static function environmentMismatch(string $keyEnvironment, string $requiredEnvironment): self
    {
        return new self(
            "Environment mismatch. This API key is for '{$keyEnvironment}' but '{$requiredEnvironment}' is required.",
            'AUTH_ENV_MISMATCH',
            [
                'key_environment' => $keyEnvironment,
                'required_environment' => $requiredEnvironment,
            ],
            403
        );
    }

    /**
     * Get response array for API.
     */
    public function toArray(): array
    {
        return [
            'error' => true,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
