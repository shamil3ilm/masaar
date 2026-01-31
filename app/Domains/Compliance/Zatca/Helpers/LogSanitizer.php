<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Helpers;

/**
 * Log Sanitizer - Prevents sensitive data from appearing in logs.
 *
 * CRITICAL: Private keys, passwords, tokens, and other sensitive data
 * must NEVER appear in logs. This helper sanitizes data before logging.
 */
class LogSanitizer
{
    /**
     * Patterns to detect and redact.
     */
    private const SENSITIVE_PATTERNS = [
        // Private keys
        '/-----BEGIN (RSA |EC |DSA |ENCRYPTED )?PRIVATE KEY-----.*?-----END (RSA |EC |DSA |ENCRYPTED )?PRIVATE KEY-----/s',
        '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',

        // Passwords and secrets
        '/password["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i',
        '/secret["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i',
        '/api[_-]?key["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i',
        '/bearer\s+[a-zA-Z0-9._-]+/i',
        '/authorization["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i',

        // JWT tokens
        '/eyJ[a-zA-Z0-9_-]+\.eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+/i',

        // Credit card patterns (PCI compliance)
        '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',

        // Base64 encoded private keys (common in certificates)
        '/MIIE[a-zA-Z0-9+\/=]{100,}/',
        '/MII[CD][a-zA-Z0-9+\/=]{100,}/',
    ];

    /**
     * Field names that should always be redacted.
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'private_key',
        'privateKey',
        'secret',
        'api_key',
        'apiKey',
        'token',
        'access_token',
        'accessToken',
        'refresh_token',
        'refreshToken',
        'bearer',
        'authorization',
        'credential',
        'credentials',
        'certificate_key',
        'ssl_key',
        'pem',
        'key_content',
        'private_key_content',
        'signed_xml', // May contain embedded signatures
    ];

    /**
     * Redaction placeholder.
     */
    private const REDACTED = '[REDACTED]';

    /**
     * Partial redaction (shows first/last chars).
     */
    private const PARTIAL_REDACT_LENGTH = 4;

    /**
     * Sanitize data for logging.
     *
     * @param mixed $data Data to sanitize (string, array, or object)
     * @return mixed Sanitized data
     */
    public static function sanitize(mixed $data): mixed
    {
        if (is_string($data)) {
            return self::sanitizeString($data);
        }

        if (is_array($data)) {
            return self::sanitizeArray($data);
        }

        if (is_object($data)) {
            return self::sanitizeObject($data);
        }

        return $data;
    }

    /**
     * Sanitize a string value.
     */
    public static function sanitizeString(string $value): string
    {
        // Apply pattern-based redaction
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $value = preg_replace($pattern, self::REDACTED, $value);
        }

        return $value;
    }

    /**
     * Sanitize an array recursively.
     */
    public static function sanitizeArray(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Check if this is a sensitive field
            if (self::isSensitiveField($lowerKey)) {
                $sanitized[$key] = self::redactValue($value);
                continue;
            }

            // Recursively sanitize nested data
            $sanitized[$key] = self::sanitize($value);
        }

        return $sanitized;
    }

    /**
     * Sanitize an object by converting to array first.
     */
    public static function sanitizeObject(object $data): array
    {
        if (method_exists($data, 'toArray')) {
            return self::sanitizeArray($data->toArray());
        }

        return self::sanitizeArray((array) $data);
    }

    /**
     * Check if a field name indicates sensitive data.
     */
    private static function isSensitiveField(string $fieldName): bool
    {
        foreach (self::SENSITIVE_FIELDS as $sensitive) {
            if (str_contains($fieldName, strtolower($sensitive))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redact a value, keeping partial visibility for debugging.
     */
    private static function redactValue(mixed $value): string
    {
        if (!is_string($value) || strlen($value) === 0) {
            return self::REDACTED;
        }

        $length = strlen($value);

        // For short values, fully redact
        if ($length <= self::PARTIAL_REDACT_LENGTH * 2) {
            return self::REDACTED;
        }

        // For longer values, show first and last few characters
        $first = substr($value, 0, self::PARTIAL_REDACT_LENGTH);
        $last = substr($value, -self::PARTIAL_REDACT_LENGTH);

        return $first . '...' . self::REDACTED . '...' . $last;
    }

    /**
     * Sanitize exception for logging.
     * Includes stack trace sanitization.
     */
    public static function sanitizeException(\Throwable $e): array
    {
        return [
            'class' => get_class($e),
            'message' => self::sanitizeString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::sanitizeTrace($e->getTraceAsString()),
            'previous' => $e->getPrevious()
                ? self::sanitizeException($e->getPrevious())
                : null,
        ];
    }

    /**
     * Sanitize stack trace.
     */
    private static function sanitizeTrace(string $trace): string
    {
        // Remove argument values from trace (may contain sensitive data)
        $trace = preg_replace('/\([^)]*\)/', '(...)', $trace);

        // Apply string sanitization
        return self::sanitizeString($trace);
    }

    /**
     * Create a safe log context array.
     * Use this when preparing data for Log::* methods.
     */
    public static function context(array $data): array
    {
        return self::sanitizeArray($data);
    }

    /**
     * Mask a value for display (e.g., in API responses).
     * Shows more than redact but still protects the value.
     */
    public static function mask(string $value, int $visibleChars = 4): string
    {
        $length = strlen($value);

        if ($length <= $visibleChars * 2) {
            return str_repeat('*', $length);
        }

        $first = substr($value, 0, $visibleChars);
        $last = substr($value, -$visibleChars);
        $middle = str_repeat('*', min($length - ($visibleChars * 2), 10));

        return $first . $middle . $last;
    }

    /**
     * Hash a value for logging (allows correlation without exposure).
     */
    public static function hashForLog(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}
