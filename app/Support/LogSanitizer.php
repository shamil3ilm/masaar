<?php

declare(strict_types=1);

namespace App\Support;

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

        // This platform's own API key, by its shape rather than by the name
        // of whichever field it arrives in. License::generateApiKey emits
        // cp_live_ or cp_test_ followed by 48 random characters, and that is
        // distinctive enough to catch wherever it appears: echoed back inside
        // a licence server's error body, embedded in a Guzzle exception
        // message alongside the URI, or written twelve characters at a time
        // by a failed-authentication warning.
        '/\bcp_(live|test)_[A-Za-z0-9]{8,}/',

        // The licence key, which api[_-]?key above does not match. This is
        // the one UsageReportingService sends in a POST body and then logs
        // the response to, hourly, so a licence server that echoes the
        // request on a validation error persists it into every rotation.
        '/licen[cs]e[_-]?key["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i',

        // The one-time password onboarding is given from the ZATCA Fatoora
        // portal. It buys a production certificate, so it is a credential for
        // as long as it is unused.
        '/\botp["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i',

        // JWT tokens
        '/eyJ[a-zA-Z0-9_-]+\.eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+/i',

        // Credit card patterns (PCI compliance)
        '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',

        // IBANs: a country code, two check digits and the account, grouped
        // in fours or not.
        '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}(?: ?[A-Z0-9]{1,3})?\b/',

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
        'api_secret',
        'apiSecret',
        'license_key',
        'licenseKey',
        'licence_key',
        'otp',
        'csr', // Carries the public key and the subject, and pairs with a key
    ];

    /**
     * Redaction placeholder.
     */
    private const REDACTED = '[REDACTED]';

    /**
     * How deep to walk a context before giving up on it.
     *
     * Eight is past anything a log line is read for. This exists because the
     * sanitiser now runs on every record through a channel tap, so a context
     * holding a model with relations, or an exception, is no longer somebody
     * else's problem.
     */
    private const MAX_DEPTH = 8;

    private const TRUNCATED = '[TRUNCATED]';

    /**
     * Partial redaction (shows first/last chars).
     */
    private const PARTIAL_REDACT_LENGTH = 4;

    /**
     * Sanitize data for logging.
     *
     * @param  mixed  $data  Data to sanitize (string, array, or object)
     * @return mixed Sanitized data
     */
    public static function sanitize(mixed $data, int $depth = 0): mixed
    {
        if (is_string($data)) {
            return self::sanitizeString($data);
        }

        // Deep enough. A log line is read by a person, and anything past this
        // is structure rather than information.
        if ($depth >= self::MAX_DEPTH) {
            return is_scalar($data) || $data === null ? $data : self::TRUNCATED;
        }

        if (is_array($data)) {
            return self::sanitizeArray($data, $depth + 1);
        }

        if ($data instanceof \Throwable) {
            // Its own method, which returns a bounded summary. Casting a
            // Throwable to an array exposes the trace, and a trace frame
            // holds the arguments the call was made with - objects included -
            // along with the previous-exception chain. Walking that is what
            // exhausted the process once this ran on every record.
            return self::sanitizeException($data);
        }

        if (is_object($data)) {
            return self::sanitizeObject($data, $depth + 1);
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
    public static function sanitizeArray(array $data, int $depth = 0): array
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
            $sanitized[$key] = self::sanitize($value, $depth);
        }

        return $sanitized;
    }

    /**
     * Sanitize an object by converting to array first.
     */
    public static function sanitizeObject(object $data, int $depth = 0): array
    {
        if (method_exists($data, 'toArray')) {
            return self::sanitizeArray($data->toArray(), $depth);
        }

        // Named rather than cast. (array) on an arbitrary object walks every
        // property it happens to hold, which for a connection, a container or
        // a closure binding is the whole application. A class name is what a
        // person reading the line can act on anyway.
        return ['object' => $data::class];
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
        if (! is_string($value) || strlen($value) === 0) {
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

        return $first.'...'.self::REDACTED.'...'.$last;
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

        return $first.$middle.$last;
    }

    /**
     * Hash a value for logging (allows correlation without exposure).
     */
    public static function hashForLog(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}
