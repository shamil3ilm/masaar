<?php

/**
 * ZATCA E-Invoicing Configuration.
 *
 * Centralized configuration for ZATCA compliance operations.
 * All values can be overridden via environment variables.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | ZATCA Environment
    |--------------------------------------------------------------------------
    |
    | Controls which ZATCA API environment to use.
    | Options: 'sandbox', 'simulation', 'production'
    |
    */
    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'sandbox' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | Credentials issued by ZATCA for API authentication.
    |
    */
    'credentials' => [
        'username' => env('ZATCA_USERNAME'),
        'password' => env('ZATCA_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Settings
    |--------------------------------------------------------------------------
    |
    | CSID (Cryptographic Stamp Identifier) for signing invoices.
    |
    */
    'certificate' => [
        'path' => env('ZATCA_CERTIFICATE_PATH'),
        'private_key_path' => env('ZATCA_PRIVATE_KEY_PATH'),
        'expiry_warning_days' => env('ZATCA_CERT_WARNING_DAYS', 30),
        'expiry_critical_days' => env('ZATCA_CERT_CRITICAL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => env('ZATCA_TIMEOUT', 30),
    'connect_timeout' => env('ZATCA_CONNECT_TIMEOUT', 10),
    'retry_attempts' => env('ZATCA_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('ZATCA_RETRY_DELAY', 1000), // milliseconds
    'ssl_verify' => env('ZATCA_SSL_VERIFY', true),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Protect against excessive API usage and ensure fair resource allocation.
    |
    */
    'rate_limits' => [
        'per_minute' => env('ZATCA_RATE_LIMIT_PER_MINUTE', 60),
        'per_day' => env('ZATCA_RATE_LIMIT_PER_DAY', 10000),
        'max_concurrent' => env('ZATCA_MAX_CONCURRENT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Prevent duplicate submissions during retries.
    |
    */
    'idempotency' => [
        'window_hours' => env('ZATCA_IDEMPOTENCY_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'large_invoice_amount' => env('ZATCA_LARGE_INVOICE_THRESHOLD', 1000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Automatic protection against cascading failures when ZATCA is unavailable.
    |
    */
    'circuit_breaker' => [
        'enabled' => env('ZATCA_CIRCUIT_BREAKER_ENABLED', true),
        'threshold' => env('ZATCA_CB_THRESHOLD', 5),       // Failures before opening
        'timeout' => env('ZATCA_CB_TIMEOUT', 60),          // Seconds before half-open
        'sample_size' => env('ZATCA_CB_SAMPLE_SIZE', 10),  // Requests to sample
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Mode
    |--------------------------------------------------------------------------
    |
    | Queue invoices when ZATCA is unavailable (for POS/retail scenarios).
    |
    */
    'offline' => [
        'enabled' => env('ZATCA_OFFLINE_ENABLED', true),
        'queue_max_size' => env('ZATCA_OFFLINE_QUEUE_MAX', 10000),
        'retry_interval' => env('ZATCA_OFFLINE_RETRY_INTERVAL', 300), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Timestamp Authority (TSA) for XAdES-T
    |--------------------------------------------------------------------------
    |
    | Optional timestamp server for XAdES-T signatures.
    |
    */
    'tsa' => [
        'enabled' => env('ZATCA_TSA_ENABLED', false),
        'url' => env('ZATCA_TSA_URL'),
        'username' => env('ZATCA_TSA_USERNAME'),
        'password' => env('ZATCA_TSA_PASSWORD'),
        'timeout' => env('ZATCA_TSA_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Values
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'payment_means_code' => env('ZATCA_DEFAULT_PAYMENT_MEANS', '10'), // Cash
        'currency' => env('ZATCA_DEFAULT_CURRENCY', 'SAR'),
        'country' => env('ZATCA_DEFAULT_COUNTRY', 'SA'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable/disable specific features for gradual rollout or testing.
    |
    */
    'features' => [
        'async_submission' => env('ZATCA_FEATURE_ASYNC', true),
        'offline_mode' => env('ZATCA_FEATURE_OFFLINE', true),
        'circuit_breaker' => env('ZATCA_FEATURE_CIRCUIT_BREAKER', true),
        'timestamp_authority' => env('ZATCA_FEATURE_TSA', false),
        'certificate_revocation_check' => env('ZATCA_FEATURE_CRL_CHECK', true),
        'arabic_normalization' => env('ZATCA_FEATURE_ARABIC_NORM', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for async submission queue processing.
    |
    */
    'queue' => [
        'connection' => env('ZATCA_QUEUE_CONNECTION', 'redis'),
        'name' => env('ZATCA_QUEUE_NAME', 'zatca-submissions'),
        'tries' => env('ZATCA_QUEUE_TRIES', 3),
        'backoff' => [10, 60, 300], // seconds between retries
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('ZATCA_LOG_CHANNEL', 'stack'),
        'level' => env('ZATCA_LOG_LEVEL', 'info'),
        'sanitize_xml' => env('ZATCA_LOG_SANITIZE', true), // Remove sensitive data
    ],

];
