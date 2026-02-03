<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform License Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration controls the platform licensing system for CompliPay
    | deployments. Partners must have a valid license key to run the platform.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | License Validation Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable license validation. Set to false for development
    | environments where license checking should be bypassed.
    |
    */
    'enabled' => env('PLATFORM_LICENSE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | License Key
    |--------------------------------------------------------------------------
    |
    | The platform license key provided by CompliPay. Format:
    | {PARTNER}-{TYPE}-{EXPIRY_YYYYMMDD}-{SIGNATURE}
    |
    | Example: TAXFLY-TRIAL-20260303-a1b2c3d4
    |
    */
    'key' => env('PLATFORM_LICENSE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | License Server URL
    |--------------------------------------------------------------------------
    |
    | URL of the license validation server for phone-home verification.
    | If not set, the system will use offline validation only.
    |
    | Example: https://license.complipay.com
    |
    */
    'server_url' => env('PLATFORM_LICENSE_SERVER_URL'),

    /*
    |--------------------------------------------------------------------------
    | Signing Secret
    |--------------------------------------------------------------------------
    |
    | Secret key used to sign and verify license keys. This must match
    | the secret used to generate the license key.
    |
    | IMPORTANT: Change this in production!
    |
    */
    'signing_secret' => env('PLATFORM_LICENSE_SECRET', 'complipay-change-this-secret-in-production'),

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    |
    | API paths that should bypass license validation. Useful for health
    | checks and license status endpoints.
    |
    */
    'excluded_paths' => [
        'api/health',
        'api/license/status',
        'up', // Laravel health check
    ],

    /*
    |--------------------------------------------------------------------------
    | Grace Period Days
    |--------------------------------------------------------------------------
    |
    | Number of days after license expiration to allow continued operation
    | in a degraded mode (read-only, no new submissions).
    |
    */
    'grace_period_days' => env('PLATFORM_LICENSE_GRACE_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Warning Threshold Days
    |--------------------------------------------------------------------------
    |
    | Number of days before expiration to start showing warnings.
    |
    */
    'warning_threshold_days' => env('PLATFORM_LICENSE_WARNING_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Cache Duration
    |--------------------------------------------------------------------------
    |
    | How long to cache license validation results (in seconds).
    | Reduces load on license server for phone-home validation.
    |
    */
    'cache_duration' => env('PLATFORM_LICENSE_CACHE_SECONDS', 3600),
];
