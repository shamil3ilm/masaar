<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * Formal API Error Taxonomy.
 *
 * Standardized error codes for SDK consumers and API clients.
 * Each error is classified as retryable or fatal.
 *
 * Format: {CATEGORY}_{SPECIFIC_ERROR}
 * Categories:
 * - AUTH: Authentication/Authorization
 * - VAL: Validation
 * - ZATCA: ZATCA-specific
 * - CERT: Certificate
 * - SIGN: Signing
 * - NET: Network/Infrastructure
 * - RATE: Rate limiting
 * - SYS: System/Internal
 */
enum ErrorCode: string
{
    // ============================================================
    // AUTHENTICATION ERRORS (AUTH_*) - Generally not retryable
    // ============================================================
    case AUTH_ORGANIZATION_SUSPENDED = 'AUTH_006';
    case AUTH_ENVIRONMENT_MISMATCH = 'AUTH_010';
    case AUTH_ORGANIZATION_UNDER_REVIEW = 'AUTH_011';
    case AUTH_LICENSE_REQUIRED = 'AUTH_012';
    case AUTH_EMAIL_NOT_VERIFIED = 'AUTH_013';
    case AUTH_FEATURE_SWITCHED_OFF = 'AUTH_014';

    // ============================================================
    // VALIDATION ERRORS (VAL_*) - Not retryable without changes
    // ============================================================
    case VAL_MISSING_REQUIRED_FIELD = 'VAL_001';
    case VAL_INVALID_FORMAT = 'VAL_002';
    case VAL_INVOICE_ALREADY_ISSUED = 'VAL_025';
    case VAL_INVOICE_NUMBER_TAKEN = 'VAL_026';
    case VALIDATION_FAILED = 'VAL_099';        // Generic validation failure

    // ============================================================
    // ZATCA ERRORS (ZATCA_*) - Some retryable
    // ============================================================
    case ZATCA_INVALID_HASH = 'ZATCA_003';
    case ZATCA_INVALID_QR_CODE = 'ZATCA_005';
    case ZATCA_INVOICE_ALREADY_CLEARED = 'ZATCA_008';
    case ZATCA_INVOICE_ALREADY_REPORTED = 'ZATCA_009';
    case ZATCA_SERVICE_UNAVAILABLE = 'ZATCA_016';      // Retryable
    case ZATCA_TIMEOUT = 'ZATCA_017';                  // Retryable
    case ZATCA_RATE_LIMITED = 'ZATCA_018';             // Retryable
    case ZATCA_MAINTENANCE = 'ZATCA_019';              // Retryable
    // The authority answered without judging the document (Detailed
    // Guidelines, responses and required actions): not a rejection.
    case ZATCA_UNAUTHORIZED = 'ZATCA_020';             // 401/403: fix the CSID and secret, then resend
    case ZATCA_NOT_PROCESSED = 'ZATCA_021';            // 404, 409, 413, an unreadable 400: resend once fixed

    // ============================================================
    // CERTIFICATE ERRORS (CERT_*) - Generally not retryable
    // ============================================================
    case CERT_NOT_FOUND = 'CERT_001';
    case CERT_EXPIRED = 'CERT_002';
    case CERT_REVOKED = 'CERT_004';
    case CERT_INVALID_FORMAT = 'CERT_005';
    case CERT_REVOCATION_UNVERIFIED = 'CERT_006';     // Retryable once the revocation list is reachable

    // ============================================================
    // NETWORK ERRORS (NET_*) - Generally retryable
    // ============================================================
    case NET_CONNECTION_FAILED = 'NET_001';
    case NET_TIMEOUT = 'NET_002';
    case NET_DNS_RESOLUTION_FAILED = 'NET_003';
    case NET_HOST_UNREACHABLE = 'NET_006';

    // ============================================================
    // RATE LIMITING ERRORS (RATE_*) - Retryable with backoff
    // ============================================================
    case RATE_LIMIT_EXCEEDED = 'RATE_001';
    case RATE_QUOTA_EXCEEDED = 'RATE_002';
    case RATE_CONCURRENT_LIMIT = 'RATE_003';
    case RATE_DAILY_LIMIT = 'RATE_004';

    // ============================================================
    // SYSTEM ERRORS (SYS_*) - Some retryable
    // ============================================================
    case SYS_INTERNAL_ERROR = 'SYS_001';
    case SYS_MAINTENANCE_MODE = 'SYS_007';             // Retryable
    case SYS_BUSY = 'SYS_008';                         // Retryable: a deadlock or lock wait, nothing written

    // ============================================================
    // IDEMPOTENCY ERRORS (IDEM_*) - Special handling
    // ============================================================
    case IDEM_REQUEST_MISMATCH = 'IDEM_003';
    case IDEM_PROCESSING_IN_PROGRESS = 'IDEM_004';

    /**
     * Check if this error is retryable.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [
            // ZATCA retryable
            self::ZATCA_SERVICE_UNAVAILABLE,
            self::ZATCA_TIMEOUT,
            self::ZATCA_RATE_LIMITED,
            self::ZATCA_MAINTENANCE,

            // Network retryable
            self::NET_CONNECTION_FAILED,
            self::NET_TIMEOUT,
            self::NET_DNS_RESOLUTION_FAILED,
            self::NET_HOST_UNREACHABLE,

            // Rate limiting retryable
            self::RATE_LIMIT_EXCEEDED,
            self::RATE_QUOTA_EXCEEDED,
            self::RATE_CONCURRENT_LIMIT,
            self::RATE_DAILY_LIMIT,

            // System retryable
            self::SYS_MAINTENANCE_MODE,
            self::SYS_BUSY,

            // A revocation list the CA serves again
            self::CERT_REVOCATION_UNVERIFIED,

            // Idempotency special
            self::IDEM_PROCESSING_IN_PROGRESS,
        ], true);
    }

    /**
     * Get recommended retry delay in seconds.
     */
    public function getRetryDelay(): int
    {
        return match ($this) {
            // Rate limiting - longer delays
            self::RATE_LIMIT_EXCEEDED => 60,
            self::RATE_QUOTA_EXCEEDED => 300,
            self::RATE_CONCURRENT_LIMIT => 5,
            self::RATE_DAILY_LIMIT => 3600,

            // ZATCA service issues
            self::ZATCA_SERVICE_UNAVAILABLE => 30,
            self::ZATCA_TIMEOUT => 10,
            self::ZATCA_RATE_LIMITED => 60,
            self::ZATCA_MAINTENANCE => 300,

            // Network issues
            self::NET_CONNECTION_FAILED => 5,
            self::NET_TIMEOUT => 10,
            self::NET_DNS_RESOLUTION_FAILED => 30,
            self::NET_HOST_UNREACHABLE => 60,

            // System issues
            self::SYS_MAINTENANCE_MODE => 60,
            self::CERT_REVOCATION_UNVERIFIED => 300,

            // A device's lock is held for one signature, about half a second.
            self::SYS_BUSY => 2,

            // In-progress
            self::IDEM_PROCESSING_IN_PROGRESS => 2,

            // Default
            default => 0,
        };
    }

    /**
     * Get maximum retry attempts for this error.
     */
    public function getMaxRetries(): int
    {
        return match ($this) {
            // Don't retry at all
            self::RATE_DAILY_LIMIT => 0,
            self::RATE_QUOTA_EXCEEDED => 1,

            // Limited retries
            self::ZATCA_MAINTENANCE => 2,
            self::SYS_MAINTENANCE_MODE => 2,

            // Standard retries
            self::ZATCA_SERVICE_UNAVAILABLE,
            self::ZATCA_TIMEOUT,
            self::NET_TIMEOUT,
            self::NET_CONNECTION_FAILED => 3,

            // More aggressive retries for transient issues
            self::IDEM_PROCESSING_IN_PROGRESS => 10,

            default => $this->isRetryable() ? 3 : 0,
        };
    }

    /**
     * Get HTTP status code for this error.
     */
    public function getHttpStatus(): int
    {
        return match ($this) {
            // 400 Bad Request - the request could not be read as an invoice.
            //
            // The line between this and 422 across the VAL_* block: 400 when
            // a field is absent or unparseable, 422 when it parsed and then
            // broke a rule.
            self::VAL_MISSING_REQUIRED_FIELD,
            self::VAL_INVALID_FORMAT,
            self::IDEM_REQUEST_MISMATCH => 400,

            // 403 Forbidden - the credential was read and refused.
            self::AUTH_ORGANIZATION_SUSPENDED,
            self::AUTH_ENVIRONMENT_MISMATCH,
            self::AUTH_ORGANIZATION_UNDER_REVIEW,
            self::AUTH_LICENSE_REQUIRED,
            self::AUTH_EMAIL_NOT_VERIFIED,
            self::AUTH_FEATURE_SWITCHED_OFF,
            self::CERT_EXPIRED,
            self::CERT_REVOKED => 403,

            // 404 Not Found
            self::CERT_NOT_FOUND => 404,

            // 409 Conflict
            self::ZATCA_INVOICE_ALREADY_CLEARED,
            self::ZATCA_INVOICE_ALREADY_REPORTED,
            self::VAL_INVOICE_NUMBER_TAKEN,
            self::IDEM_PROCESSING_IN_PROGRESS => 409,

            // 422 Unprocessable Entity - read, understood, and refused by
            // a rule. Every VAL_* that is not a shape failure or a duplicate
            // belongs here, and ErrorTaxonomyTest holds the whole block to a
            // 4xx so a malformed invoice can never be reported as our fault.
            self::ZATCA_INVALID_HASH,
            self::ZATCA_INVALID_QR_CODE,
            self::VALIDATION_FAILED,
            self::CERT_INVALID_FORMAT,

            // 422 rather than the 409 the other "already" codes carry: this
            // one is raised before any call to the authority, by the same
            // invoice-state rule ComplianceController::submit() already
            // answers 422 for, and the bootstrap handler renders it on routes
            // the controller does not wrap. One status for one refusal.
            self::VAL_INVOICE_ALREADY_ISSUED => 422,

            // 429 Too Many Requests
            self::RATE_LIMIT_EXCEEDED,
            self::RATE_QUOTA_EXCEEDED,
            self::RATE_CONCURRENT_LIMIT,
            self::RATE_DAILY_LIMIT,
            self::ZATCA_RATE_LIMITED => 429,

            // 500 Internal Server Error - ours, and nothing the caller can
            // change by sending a different request.
            self::SYS_INTERNAL_ERROR => 500,

            // 502 Bad Gateway - an upstream could not be reached or parsed,
            // or answered without judging the document.
            self::ZATCA_UNAUTHORIZED,
            self::ZATCA_NOT_PROCESSED,
            self::NET_CONNECTION_FAILED,
            self::NET_DNS_RESOLUTION_FAILED,
            self::NET_HOST_UNREACHABLE => 502,

            // 503 Service Unavailable - temporary, and worth repeating. Every
            // code isRetryable() names has to answer 408, 409, 429 or a 5xx,
            // or the status and the retry advice contradict each other.
            self::ZATCA_SERVICE_UNAVAILABLE,
            self::ZATCA_MAINTENANCE,
            self::SYS_MAINTENANCE_MODE,
            self::SYS_BUSY,
            self::CERT_REVOCATION_UNVERIFIED => 503,

            // 504 Gateway Timeout
            self::ZATCA_TIMEOUT,
            self::NET_TIMEOUT => 504,

            // No default. Every case above is named, so the match is
            // exhaustive and a new code added without a status fails loudly
            // instead of arriving as a blank 500. ErrorTaxonomyTest catches
            // it in CI before it can get that far.
        };
    }

    /**
     * Get human-readable error message.
     */
    public function getMessage(): string
    {
        return match ($this) {
            // Auth
            self::AUTH_ORGANIZATION_SUSPENDED => 'Organization account is suspended',
            self::AUTH_ENVIRONMENT_MISMATCH => 'License environment does not match the target environment',
            self::AUTH_ORGANIZATION_UNDER_REVIEW => 'Organization has not been approved to file yet',
            self::AUTH_LICENSE_REQUIRED => 'Organization has no active plan',
            self::AUTH_EMAIL_NOT_VERIFIED => 'Email address has not been verified',
            self::AUTH_FEATURE_SWITCHED_OFF => 'Feature is switched off for this organization',

            // Validation
            self::VAL_MISSING_REQUIRED_FIELD => 'Required field is missing',
            self::VAL_INVALID_FORMAT => 'Invalid data format',
            self::VAL_INVOICE_ALREADY_ISSUED => 'Invoice has already been issued and cannot be issued again',
            self::VAL_INVOICE_NUMBER_TAKEN => 'The organization already has an invoice with this number',

            // ZATCA
            self::ZATCA_SERVICE_UNAVAILABLE => 'The tax authority service is temporarily unavailable',
            self::ZATCA_TIMEOUT => 'The tax authority service request timed out',
            self::ZATCA_RATE_LIMITED => 'The tax authority rate limit was exceeded',
            self::ZATCA_MAINTENANCE => 'The tax authority service is under maintenance',
            self::ZATCA_UNAUTHORIZED => 'The tax authority refused the device certificate and secret; nothing was judged',
            self::ZATCA_NOT_PROCESSED => 'The tax authority did not process the document; nothing was judged',

            // Certificate
            self::CERT_REVOKED => 'Certificate has been revoked',
            self::CERT_NOT_FOUND => 'Certificate not found',

            // Network
            self::NET_TIMEOUT => 'Connection timed out',

            // Rate limiting
            self::RATE_LIMIT_EXCEEDED => 'Rate limit exceeded. Please slow down.',
            self::RATE_DAILY_LIMIT => 'Daily request limit exceeded',

            // System
            self::SYS_INTERNAL_ERROR => 'An internal error occurred',
            self::SYS_MAINTENANCE_MODE => 'System is under maintenance',
            self::SYS_BUSY => 'The request met another one changing the same records and was not completed; nothing was saved',

            // Idempotency
            self::IDEM_PROCESSING_IN_PROGRESS => 'A request with this idempotency key is already being processed',
            self::IDEM_REQUEST_MISMATCH => 'This idempotency key was used with a different request body',

            // The cases below answered 'An error occurred' through a default
            // arm. Each names the field or the condition at fault, because
            // the message is the only part of the response a person reads,
            // and a code alone sends them to the documentation.
            self::VALIDATION_FAILED => 'The invoice did not pass validation',

            self::ZATCA_INVALID_HASH => 'The tax authority rejected the document hash',
            self::ZATCA_INVALID_QR_CODE => 'The tax authority rejected the QR code payload',

            self::CERT_INVALID_FORMAT => 'Certificate could not be read; expected PEM or base64 DER',
            self::CERT_EXPIRED => 'Certificate has expired and must be renewed',
            self::CERT_REVOCATION_UNVERIFIED => 'The certificate revocation status could not be checked against a current revocation list',

            self::NET_CONNECTION_FAILED => 'The connection to the tax authority could not be established',
            self::NET_DNS_RESOLUTION_FAILED => 'The tax authority hostname could not be resolved',
            self::NET_HOST_UNREACHABLE => 'The tax authority host is unreachable',

            self::ZATCA_INVOICE_ALREADY_CLEARED => 'This invoice has already been cleared by the tax authority',
            self::ZATCA_INVOICE_ALREADY_REPORTED => 'This invoice has already been reported to the tax authority',

            self::RATE_QUOTA_EXCEEDED => 'The request quota for this plan has been exhausted',
            self::RATE_CONCURRENT_LIMIT => 'Too many requests are in flight for this organization at once',

            // No default, for the same reason getHttpStatus() has none.
        };
    }

    /**
     * Get error category.
     */
    public function getCategory(): string
    {
        $code = $this->value;

        return match (true) {
            str_starts_with($code, 'AUTH_') => 'authentication',
            str_starts_with($code, 'VAL_') => 'validation',
            str_starts_with($code, 'ZATCA_') => 'zatca',
            str_starts_with($code, 'CERT_') => 'certificate',
            str_starts_with($code, 'SIGN_') => 'signing',
            str_starts_with($code, 'NET_') => 'network',
            str_starts_with($code, 'RATE_') => 'rate_limit',
            str_starts_with($code, 'SYS_') => 'system',
            str_starts_with($code, 'IDEM_') => 'idempotency',
            default => 'unknown',
        };
    }

    /**
     * Convert to API response array.
     */
    public function toArray(): array
    {
        return [
            'code' => $this->value,
            'message' => $this->getMessage(),
            'category' => $this->getCategory(),
            'retryable' => $this->isRetryable(),
            'retry_after' => $this->isRetryable() ? $this->getRetryDelay() : null,
            'max_retries' => $this->getMaxRetries(),
        ];
    }
}
