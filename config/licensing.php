<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | License Caching
    |--------------------------------------------------------------------------
    |
    | Configuration for license caching to reduce database lookups.
    |
    */
    'cache' => [
        'enabled' => env('LICENSE_CACHE_ENABLED', true),
        'ttl_seconds' => env('LICENSE_CACHE_TTL', 300), // 5 minutes
        'prefix' => 'license:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for license audit logging.
    |
    */
    'audit' => [
        'enabled' => env('LICENSE_AUDIT_ENABLED', true),
        'retention_days' => env('LICENSE_AUDIT_RETENTION_DAYS', 730), // 2 years
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Settings for license-related notifications.
    |
    */
    'notifications' => [
        'expiry_warning_days' => [30, 14, 7, 3, 1],
        'quota_warning_percent' => [75, 90, 100],
        'rate_limit_notify' => true,
    ],
];
