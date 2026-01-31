<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Enums;

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
    case AUTH_INVALID_API_KEY = 'AUTH_001';
    case AUTH_EXPIRED_TOKEN = 'AUTH_002';
    case AUTH_INVALID_TOKEN = 'AUTH_003';
    case AUTH_MISSING_CREDENTIALS = 'AUTH_004';
    case AUTH_INSUFFICIENT_PERMISSIONS = 'AUTH_005';
    case AUTH_ORGANIZATION_SUSPENDED = 'AUTH_006';
    case AUTH_ORGANIZATION_NOT_FOUND = 'AUTH_007';
    case AUTH_LICENSE_EXPIRED = 'AUTH_008';
    case AUTH_LICENSE_REVOKED = 'AUTH_009';
    case AUTH_ENVIRONMENT_MISMATCH = 'AUTH_010';

    // ============================================================
    // VALIDATION ERRORS (VAL_*) - Not retryable without changes
    // ============================================================
    case VAL_MISSING_REQUIRED_FIELD = 'VAL_001';
    case VAL_INVALID_FORMAT = 'VAL_002';
    case VAL_INVALID_VAT_NUMBER = 'VAL_003';
    case VAL_INVALID_INVOICE_TYPE = 'VAL_004';
    case VAL_INVALID_TAX_CATEGORY = 'VAL_005';
    case VAL_MISSING_EXEMPTION_REASON = 'VAL_006';
    case VAL_INVALID_EXEMPTION_CODE = 'VAL_007';
    case VAL_INVALID_CURRENCY = 'VAL_008';
    case VAL_CALCULATION_MISMATCH = 'VAL_009';
    case VAL_NEGATIVE_AMOUNT = 'VAL_010';
    case VAL_DUPLICATE_INVOICE_NUMBER = 'VAL_011';
    case VAL_INVALID_DATE_FORMAT = 'VAL_012';
    case VAL_FUTURE_DATE_NOT_ALLOWED = 'VAL_013';
    case VAL_INVOICE_TOO_OLD = 'VAL_014';
    case VAL_MISSING_BILLING_REFERENCE = 'VAL_015';
    case VAL_INVALID_BILLING_REFERENCE = 'VAL_016';
    case VAL_LINE_ITEM_INVALID = 'VAL_017';
    case VAL_QUANTITY_INVALID = 'VAL_018';
    case VAL_UNIT_PRICE_INVALID = 'VAL_019';
    case VAL_TAX_RATE_MISMATCH = 'VAL_020';
    case VAL_BUYER_INFO_INCOMPLETE = 'VAL_021';
    case VAL_SELLER_INFO_INCOMPLETE = 'VAL_022';
    case VAL_ADDRESS_INCOMPLETE = 'VAL_023';
    case VAL_TIMESTAMP_INVALID = 'VAL_024';
    case VALIDATION_FAILED = 'VAL_099';        // Generic validation failure

    // ============================================================
    // ZATCA ERRORS (ZATCA_*) - Some retryable
    // ============================================================
    case ZATCA_CLEARANCE_REJECTED = 'ZATCA_001';
    case ZATCA_REPORTING_REJECTED = 'ZATCA_002';
    case ZATCA_INVALID_HASH = 'ZATCA_003';
    case ZATCA_INVALID_SIGNATURE = 'ZATCA_004';
    case ZATCA_INVALID_QR_CODE = 'ZATCA_005';
    case ZATCA_INVALID_UUID = 'ZATCA_006';
    case ZATCA_DUPLICATE_SUBMISSION = 'ZATCA_007';
    case ZATCA_INVOICE_ALREADY_CLEARED = 'ZATCA_008';
    case ZATCA_INVOICE_ALREADY_REPORTED = 'ZATCA_009';
    case ZATCA_COMPLIANCE_CHECK_FAILED = 'ZATCA_010';
    case ZATCA_BUSINESS_RULE_VIOLATION = 'ZATCA_011';
    case ZATCA_INVALID_CERTIFICATE = 'ZATCA_012';
    case ZATCA_CERTIFICATE_NOT_AUTHORIZED = 'ZATCA_013';
    case ZATCA_ONBOARDING_REQUIRED = 'ZATCA_014';
    case ZATCA_PRODUCTION_NOT_ENABLED = 'ZATCA_015';
    case ZATCA_SERVICE_UNAVAILABLE = 'ZATCA_016';      // Retryable
    case ZATCA_TIMEOUT = 'ZATCA_017';                  // Retryable
    case ZATCA_RATE_LIMITED = 'ZATCA_018';             // Retryable
    case ZATCA_MAINTENANCE = 'ZATCA_019';              // Retryable
    case ZATCA_UNKNOWN_ERROR = 'ZATCA_099';

    // ============================================================
    // CERTIFICATE ERRORS (CERT_*) - Generally not retryable
    // ============================================================
    case CERT_NOT_FOUND = 'CERT_001';
    case CERT_EXPIRED = 'CERT_002';
    case CERT_NOT_YET_VALID = 'CERT_003';
    case CERT_REVOKED = 'CERT_004';
    case CERT_INVALID_FORMAT = 'CERT_005';
    case CERT_CHAIN_INVALID = 'CERT_006';
    case CERT_PRIVATE_KEY_MISMATCH = 'CERT_007';
    case CERT_PRIVATE_KEY_NOT_FOUND = 'CERT_008';
    case CERT_PRIVATE_KEY_INVALID = 'CERT_009';
    case CERT_CSR_GENERATION_FAILED = 'CERT_010';
    case CERT_OCSP_CHECK_FAILED = 'CERT_011';          // Retryable
    case CERT_CRL_CHECK_FAILED = 'CERT_012';           // Retryable
    case CERT_EXPIRING_SOON = 'CERT_013';              // Warning

    // ============================================================
    // SIGNING ERRORS (SIGN_*) - Some retryable
    // ============================================================
    case SIGN_FAILED = 'SIGN_001';
    case SIGN_INVALID_KEY = 'SIGN_002';
    case SIGN_ALGORITHM_NOT_SUPPORTED = 'SIGN_003';
    case SIGN_CANONICALIZATION_FAILED = 'SIGN_004';
    case SIGN_DIGEST_MISMATCH = 'SIGN_005';
    case SIGN_TIMESTAMP_FAILED = 'SIGN_006';           // Retryable
    case SIGN_TSA_UNAVAILABLE = 'SIGN_007';            // Retryable
    case SIGN_VERIFICATION_FAILED = 'SIGN_008';

    // ============================================================
    // NETWORK ERRORS (NET_*) - Generally retryable
    // ============================================================
    case NET_CONNECTION_FAILED = 'NET_001';
    case NET_TIMEOUT = 'NET_002';
    case NET_DNS_RESOLUTION_FAILED = 'NET_003';
    case NET_SSL_CERTIFICATE_ERROR = 'NET_004';
    case NET_PROXY_ERROR = 'NET_005';
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
    case SYS_DATABASE_ERROR = 'SYS_002';               // Retryable
    case SYS_QUEUE_ERROR = 'SYS_003';                  // Retryable
    case SYS_STORAGE_ERROR = 'SYS_004';                // Retryable
    case SYS_CONFIGURATION_ERROR = 'SYS_005';
    case SYS_DEPENDENCY_ERROR = 'SYS_006';
    case SYS_MAINTENANCE_MODE = 'SYS_007';             // Retryable
    case SYS_RESOURCE_EXHAUSTED = 'SYS_008';           // Retryable

    // ============================================================
    // IDEMPOTENCY ERRORS (IDEM_*) - Special handling
    // ============================================================
    case IDEM_KEY_MISSING = 'IDEM_001';
    case IDEM_KEY_REUSED = 'IDEM_002';
    case IDEM_REQUEST_MISMATCH = 'IDEM_003';
    case IDEM_PROCESSING_IN_PROGRESS = 'IDEM_004';
    case IDEM_EXPIRED = 'IDEM_005';

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

            // Certificate retryable
            self::CERT_OCSP_CHECK_FAILED,
            self::CERT_CRL_CHECK_FAILED,

            // Signing retryable
            self::SIGN_TIMESTAMP_FAILED,
            self::SIGN_TSA_UNAVAILABLE,

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
            self::SYS_DATABASE_ERROR,
            self::SYS_QUEUE_ERROR,
            self::SYS_STORAGE_ERROR,
            self::SYS_MAINTENANCE_MODE,
            self::SYS_RESOURCE_EXHAUSTED,

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
            self::SYS_DATABASE_ERROR => 5,
            self::SYS_QUEUE_ERROR => 5,
            self::SYS_STORAGE_ERROR => 10,
            self::SYS_MAINTENANCE_MODE => 60,
            self::SYS_RESOURCE_EXHAUSTED => 30,

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
            self::SYS_DATABASE_ERROR => 5,

            default => $this->isRetryable() ? 3 : 0,
        };
    }

    /**
     * Get HTTP status code for this error.
     */
    public function getHttpStatus(): int
    {
        return match ($this) {
            // 400 Bad Request
            self::VAL_MISSING_REQUIRED_FIELD,
            self::VAL_INVALID_FORMAT,
            self::VAL_INVALID_VAT_NUMBER,
            self::VAL_CALCULATION_MISMATCH,
            self::IDEM_KEY_MISSING,
            self::IDEM_REQUEST_MISMATCH => 400,

            // 401 Unauthorized
            self::AUTH_INVALID_API_KEY,
            self::AUTH_EXPIRED_TOKEN,
            self::AUTH_INVALID_TOKEN,
            self::AUTH_MISSING_CREDENTIALS => 401,

            // 403 Forbidden
            self::AUTH_INSUFFICIENT_PERMISSIONS,
            self::AUTH_ORGANIZATION_SUSPENDED,
            self::AUTH_LICENSE_EXPIRED,
            self::AUTH_LICENSE_REVOKED,
            self::AUTH_ENVIRONMENT_MISMATCH,
            self::ZATCA_CERTIFICATE_NOT_AUTHORIZED => 403,

            // 404 Not Found
            self::AUTH_ORGANIZATION_NOT_FOUND,
            self::CERT_NOT_FOUND,
            self::CERT_PRIVATE_KEY_NOT_FOUND => 404,

            // 409 Conflict
            self::VAL_DUPLICATE_INVOICE_NUMBER,
            self::ZATCA_DUPLICATE_SUBMISSION,
            self::ZATCA_INVOICE_ALREADY_CLEARED,
            self::ZATCA_INVOICE_ALREADY_REPORTED,
            self::IDEM_KEY_REUSED,
            self::IDEM_PROCESSING_IN_PROGRESS => 409,

            // 422 Unprocessable Entity
            self::ZATCA_CLEARANCE_REJECTED,
            self::ZATCA_REPORTING_REJECTED,
            self::ZATCA_BUSINESS_RULE_VIOLATION,
            self::ZATCA_COMPLIANCE_CHECK_FAILED => 422,

            // 429 Too Many Requests
            self::RATE_LIMIT_EXCEEDED,
            self::RATE_QUOTA_EXCEEDED,
            self::RATE_CONCURRENT_LIMIT,
            self::RATE_DAILY_LIMIT,
            self::ZATCA_RATE_LIMITED => 429,

            // 500 Internal Server Error
            self::SYS_INTERNAL_ERROR,
            self::SYS_CONFIGURATION_ERROR,
            self::SIGN_FAILED => 500,

            // 502 Bad Gateway
            self::ZATCA_UNKNOWN_ERROR,
            self::NET_PROXY_ERROR => 502,

            // 503 Service Unavailable
            self::ZATCA_SERVICE_UNAVAILABLE,
            self::ZATCA_MAINTENANCE,
            self::SYS_MAINTENANCE_MODE,
            self::SYS_RESOURCE_EXHAUSTED => 503,

            // 504 Gateway Timeout
            self::ZATCA_TIMEOUT,
            self::NET_TIMEOUT => 504,

            default => 500,
        };
    }

    /**
     * Get human-readable error message.
     */
    public function getMessage(): string
    {
        return match ($this) {
            // Auth
            self::AUTH_INVALID_API_KEY => 'Invalid API key provided',
            self::AUTH_EXPIRED_TOKEN => 'Authentication token has expired',
            self::AUTH_INVALID_TOKEN => 'Invalid authentication token',
            self::AUTH_MISSING_CREDENTIALS => 'Authentication credentials are required',
            self::AUTH_INSUFFICIENT_PERMISSIONS => 'Insufficient permissions for this operation',
            self::AUTH_ORGANIZATION_SUSPENDED => 'Organization account is suspended',
            self::AUTH_ORGANIZATION_NOT_FOUND => 'Organization not found',
            self::AUTH_LICENSE_EXPIRED => 'License has expired',
            self::AUTH_LICENSE_REVOKED => 'License has been revoked',
            self::AUTH_ENVIRONMENT_MISMATCH => 'License environment does not match the target environment',

            // Validation
            self::VAL_MISSING_REQUIRED_FIELD => 'Required field is missing',
            self::VAL_INVALID_FORMAT => 'Invalid data format',
            self::VAL_INVALID_VAT_NUMBER => 'Invalid VAT registration number format',
            self::VAL_INVALID_INVOICE_TYPE => 'Invalid invoice type',
            self::VAL_INVALID_TAX_CATEGORY => 'Invalid tax category code',
            self::VAL_MISSING_EXEMPTION_REASON => 'Tax exemption reason is required for this category',
            self::VAL_INVALID_EXEMPTION_CODE => 'Invalid tax exemption reason code',
            self::VAL_CALCULATION_MISMATCH => 'Calculated totals do not match provided values',
            self::VAL_DUPLICATE_INVOICE_NUMBER => 'Invoice number already exists',

            // ZATCA
            self::ZATCA_CLEARANCE_REJECTED => 'Invoice clearance was rejected by ZATCA',
            self::ZATCA_REPORTING_REJECTED => 'Invoice reporting was rejected by ZATCA',
            self::ZATCA_DUPLICATE_SUBMISSION => 'This invoice has already been submitted',
            self::ZATCA_SERVICE_UNAVAILABLE => 'ZATCA service is temporarily unavailable',
            self::ZATCA_TIMEOUT => 'ZATCA service request timed out',
            self::ZATCA_RATE_LIMITED => 'ZATCA rate limit exceeded',
            self::ZATCA_MAINTENANCE => 'ZATCA service is under maintenance',

            // Certificate
            self::CERT_EXPIRED => 'Certificate has expired',
            self::CERT_REVOKED => 'Certificate has been revoked',
            self::CERT_NOT_FOUND => 'Certificate not found',
            self::CERT_EXPIRING_SOON => 'Certificate is expiring soon',

            // Network
            self::NET_CONNECTION_FAILED => 'Failed to connect to remote service',
            self::NET_TIMEOUT => 'Connection timed out',

            // Rate limiting
            self::RATE_LIMIT_EXCEEDED => 'Rate limit exceeded. Please slow down.',
            self::RATE_DAILY_LIMIT => 'Daily request limit exceeded',

            // System
            self::SYS_INTERNAL_ERROR => 'An internal error occurred',
            self::SYS_MAINTENANCE_MODE => 'System is under maintenance',

            // Idempotency
            self::IDEM_KEY_MISSING => 'Idempotency key is required for this operation',
            self::IDEM_KEY_REUSED => 'Idempotency key has already been used with different parameters',
            self::IDEM_PROCESSING_IN_PROGRESS => 'A request with this idempotency key is already being processed',

            default => 'An error occurred',
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
     * Map ZATCA error code to internal error code.
     */
    public static function fromZatcaError(string $zatcaCode): self
    {
        // ZATCA error code mapping
        return match (true) {
            // Authentication/Authorization
            str_contains($zatcaCode, 'UNAUTHORIZED') => self::ZATCA_CERTIFICATE_NOT_AUTHORIZED,
            str_contains($zatcaCode, 'FORBIDDEN') => self::AUTH_INSUFFICIENT_PERMISSIONS,

            // Duplicate/Conflict
            str_contains($zatcaCode, 'DUPLICATE') => self::ZATCA_DUPLICATE_SUBMISSION,
            str_contains($zatcaCode, 'ALREADY_CLEARED') => self::ZATCA_INVOICE_ALREADY_CLEARED,
            str_contains($zatcaCode, 'ALREADY_REPORTED') => self::ZATCA_INVOICE_ALREADY_REPORTED,

            // Validation
            str_contains($zatcaCode, 'INVALID_HASH') => self::ZATCA_INVALID_HASH,
            str_contains($zatcaCode, 'INVALID_SIGNATURE') => self::ZATCA_INVALID_SIGNATURE,
            str_contains($zatcaCode, 'INVALID_QR') => self::ZATCA_INVALID_QR_CODE,
            str_contains($zatcaCode, 'BR-KSA') => self::ZATCA_BUSINESS_RULE_VIOLATION,

            // Service issues
            str_contains($zatcaCode, 'TIMEOUT') => self::ZATCA_TIMEOUT,
            str_contains($zatcaCode, 'UNAVAILABLE') => self::ZATCA_SERVICE_UNAVAILABLE,
            str_contains($zatcaCode, 'RATE_LIMIT') => self::ZATCA_RATE_LIMITED,
            str_contains($zatcaCode, 'MAINTENANCE') => self::ZATCA_MAINTENANCE,

            // Onboarding
            str_contains($zatcaCode, 'NOT_ONBOARDED') => self::ZATCA_ONBOARDING_REQUIRED,
            str_contains($zatcaCode, 'COMPLIANCE') => self::ZATCA_COMPLIANCE_CHECK_FAILED,

            default => self::ZATCA_UNKNOWN_ERROR,
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
