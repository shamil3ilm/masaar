<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Config;

/**
 * Centralized ZATCA Configuration.
 *
 * All ZATCA-related constants, namespaces, and configurable values
 * are defined here to avoid scattered hardcoding.
 *
 * This addresses:
 * - Regulatory extremes (schema versioning, feature flags)
 * - Configuration centralization
 * - Easy updates when ZATCA changes requirements
 */
final class FatooraConfig
{
    // ============================================================
    // XML NAMESPACES (ZATCA UBL 2.1 Specification)
    // ============================================================
    public const UBL_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const EXT_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public const SIG_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    public const SBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    // ============================================================
    // DIGITAL SIGNATURE NAMESPACES
    // ============================================================
    public const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    public const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';

    public const C14N_NS = 'http://www.w3.org/2006/12/xml-c14n11';

    public const C14N_EXCLUSIVE_NS = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    // ============================================================
    // ALGORITHM URIs
    // ============================================================
    public const ALGO_ECDSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256';

    public const ALGO_RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    public const ALGO_SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    public const ALGO_ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public const ALGO_XPATH = 'http://www.w3.org/TR/1999/REC-xpath-19991116';

    // ============================================================
    // ZATCA API ENDPOINTS
    // ============================================================
    public const SANDBOX_BASE_URL = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';

    public const SIMULATION_BASE_URL = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation';

    public const PRODUCTION_BASE_URL = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core';

    // ============================================================
    // INVOICE TYPE CODES (KSA-specific per ZATCA)
    // ============================================================
    public const INVOICE_TYPE_STANDARD = '388';     // Standard (B2B)

    public const INVOICE_TYPE_SIMPLIFIED = '388';   // Simplified (B2C) - same code, different subtype

    public const INVOICE_TYPE_DEBIT_NOTE = '383';

    public const INVOICE_TYPE_CREDIT_NOTE = '381';

    // BT-3 subtype flags are built by InvoiceXmlData::getInvoiceTypeName() from
    // the invoice's own columns. Six constants here named the same values and
    // disagreed with it — third party as 0100001 against 0110000, nominal and
    // summary likewise transposed — while nothing read them. A second, wrong
    // statement of which bit means what is how a document ends up filed as
    // something it is not.

    // ============================================================
    // TAX CATEGORIES (UN/CEFACT 5305)
    // ============================================================
    public const TAX_CATEGORY_STANDARD = 'S';       // Standard rate (15%)

    public const TAX_CATEGORY_ZERO_RATED = 'Z';     // Zero rated

    public const TAX_CATEGORY_EXEMPT = 'E';         // Exempt

    public const TAX_CATEGORY_OUT_OF_SCOPE = 'O';   // Out of scope

    /**
     * The tax category a line belongs to, given its exemption code and rate.
     *
     * ZATCA reads the category and the exemption reason together: VATEX-SA-HEA
     * on a line taxed at 15% is a contradiction and the document is rejected
     * rather than reconciled. So the code decides, and the rate only answers
     * when there is no code.
     *
     * Order matters — the arms short-circuit, and every code begins VATEX-SA-,
     * so the specific prefixes have to be tried before the general one.
     */
    public static function taxCategoryFor(?string $exemptionCode, float $taxRate): string
    {
        if ($exemptionCode === null || $exemptionCode === '') {
            return $taxRate > 0 ? self::TAX_CATEGORY_STANDARD : self::TAX_CATEGORY_ZERO_RATED;
        }

        return match (true) {
            str_starts_with($exemptionCode, 'VATEX-SA-OOS') => self::TAX_CATEGORY_OUT_OF_SCOPE,
            str_starts_with($exemptionCode, 'VATEX-SA-HEA') => self::TAX_CATEGORY_ZERO_RATED,
            str_starts_with($exemptionCode, 'VATEX-SA-EDU') => self::TAX_CATEGORY_ZERO_RATED,
            str_starts_with($exemptionCode, 'VATEX-SA-') => self::TAX_CATEGORY_EXEMPT,
            default => $taxRate > 0 ? self::TAX_CATEGORY_STANDARD : self::TAX_CATEGORY_ZERO_RATED,
        };
    }

    // ============================================================
    // PAYMENT MEANS CODES (UN/EDIFACT 4461)
    // ============================================================
    public const PAYMENT_CASH = '10';

    public const PAYMENT_CHEQUE = '20';

    public const PAYMENT_CREDIT_TRANSFER = '30';

    public const PAYMENT_BANK_CARD = '48';

    public const PAYMENT_DIRECT_DEBIT = '49';

    // ============================================================
    // RATE LIMITS (Configurable)
    // ============================================================
    public const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;

    public const DEFAULT_RATE_LIMIT_PER_DAY = 10000;

    public const DEFAULT_MAX_CONCURRENT_SUBMISSIONS = 10;

    // ============================================================
    // IDEMPOTENCY (Configurable)
    // ============================================================
    public const DEFAULT_IDEMPOTENCY_WINDOW_HOURS = 24;

    // ============================================================
    // THRESHOLDS (Configurable)
    // ============================================================
    public const LARGE_INVOICE_THRESHOLD = 1000000.00; // SAR

    public const CERTIFICATE_EXPIRY_WARNING_DAYS = 30;

    public const CERTIFICATE_EXPIRY_CRITICAL_DAYS = 7;

    // ============================================================
    // CRYPTOGRAPHY
    // ============================================================
    public const DEFAULT_RSA_KEY_SIZE = 2048;

    public const DEFAULT_EC_CURVE = 'secp256k1';

    public const DEFAULT_HASH_ALGORITHM = 'sha256';

    // ============================================================
    // CLASSIFICATION SCHEMES
    // ============================================================
    public const CLASSIFICATION_UNSPSC = 'UNSPSC';

    public const CLASSIFICATION_HS = 'HS';          // Harmonized System

    // ============================================================
    // UBL EXTENSION IDENTIFIERS
    // ============================================================
    public const EXT_ID_ICV = 'ICV';

    public const EXT_ID_PIH = 'PIH';

    public const EXT_ID_QR = 'QR';

    // ============================================================
    // DEFAULT PIH (Previous Invoice Hash)
    // ============================================================
    // Used for the first invoice in a chain (base64-encoded SHA256 of zeros)
    public const DEFAULT_FIRST_INVOICE_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    // ============================================================
    // COUNTRY CODES
    // ============================================================
    public const COUNTRY_SA = 'SA';

    public const CURRENCY_SAR = 'SAR';

    // ============================================================
    // ALLOWANCE/CHARGE REASON CODES
    // ============================================================
    public const REASON_DISCOUNT = '95';

    public const REASON_SPECIAL_AGREEMENT = '100';

    public const REASON_PRODUCTION_ERROR = '104';

    // Circuit breaker settings live in config/fatoora.php and are read by
    // CircuitBreaker itself. Two getters here asked for circuit_breaker.threshold
    // and .timeout, which that file does not define — it has failure_threshold
    // and timeout_seconds — so both silently returned the constants below
    // instead, and ZATCA_CCB_FAILURE_THRESHOLD moved nothing through this path.

    // ============================================================
    // OFFLINE MODE SETTINGS
    // ============================================================
    public const OFFLINE_QUEUE_MAX_SIZE = 10000;

    public const OFFLINE_RETRY_INTERVAL = 300;      // 5 minutes

    // ============================================================
    // KILL SWITCH FLAGS
    // ============================================================
    public const KILL_SWITCH_CACHE_KEY = 'zatca:kill_switches';

    /**
     * Get configured value with fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return config("fatoora.{$key}", $default);
    }

    /**
     * Get rate limit per minute.
     */
    public static function getRateLimitPerMinute(): int
    {
        return (int) self::get('rate_limits.per_minute', self::DEFAULT_RATE_LIMIT_PER_MINUTE);
    }

    /**
     * Get rate limit per day.
     */
    public static function getRateLimitPerDay(): int
    {
        return (int) self::get('rate_limits.per_day', self::DEFAULT_RATE_LIMIT_PER_DAY);
    }

    /**
     * Get max concurrent submissions.
     */
    public static function getMaxConcurrentSubmissions(): int
    {
        return (int) self::get('rate_limits.max_concurrent', self::DEFAULT_MAX_CONCURRENT_SUBMISSIONS);
    }

    /**
     * Get idempotency window in hours.
     */
    public static function getIdempotencyWindowHours(): int
    {
        return (int) self::get('idempotency.window_hours', self::DEFAULT_IDEMPOTENCY_WINDOW_HOURS);
    }

    /**
     * Get large invoice threshold.
     */
    public static function getLargeInvoiceThreshold(): float
    {
        return (float) self::get('thresholds.large_invoice_amount', self::LARGE_INVOICE_THRESHOLD);
    }

    /**
     * Get certificate expiry warning days.
     */
    public static function getCertificateExpiryWarningDays(): int
    {
        return (int) self::get('certificate.expiry_warning_days', self::CERTIFICATE_EXPIRY_WARNING_DAYS);
    }

    /**
     * Get certificate expiry critical days.
     */
    public static function getCertificateExpiryCriticalDays(): int
    {
        return (int) self::get('certificate.expiry_critical_days', self::CERTIFICATE_EXPIRY_CRITICAL_DAYS);
    }

    /**
     * Get offline queue max size.
     */
    public static function getOfflineQueueMaxSize(): int
    {
        return (int) self::get('offline.queue_max_size', self::OFFLINE_QUEUE_MAX_SIZE);
    }

    /**
     * Get offline retry interval seconds.
     */
    public static function getOfflineRetryInterval(): int
    {
        return (int) self::get('offline.retry_interval', self::OFFLINE_RETRY_INTERVAL);
    }

    /**
     * Get ZATCA base URL for current environment.
     */
    public static function getBaseUrl(): string
    {
        $env = self::get('environment', 'sandbox');

        return match ($env) {
            'production' => self::PRODUCTION_BASE_URL,
            'simulation' => self::SIMULATION_BASE_URL,
            default => self::SANDBOX_BASE_URL,
        };
    }

    /**
     * Check if a feature flag is enabled.
     */
    public static function isFeatureEnabled(string $feature): bool
    {
        return (bool) self::get("features.{$feature}", false);
    }

    /**
     * Get invoice type code for document type.
     */
    public static function getInvoiceTypeCode(string $documentType): string
    {
        return match ($documentType) {
            'credit_note' => self::INVOICE_TYPE_CREDIT_NOTE,
            'debit_note' => self::INVOICE_TYPE_DEBIT_NOTE,
            default => self::INVOICE_TYPE_STANDARD,
        };
    }

    /**
     * Get default payment means code.
     */
    public static function getDefaultPaymentMeansCode(): string
    {
        return self::get('default_payment_means_code', self::PAYMENT_CASH);
    }
}
