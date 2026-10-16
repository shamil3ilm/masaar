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
    // Whether an organization needs an active licence - its plan, assigned
    // when a platform admin approves it - to onboard with ZATCA and issue.
    'filing_requires_licence' => (bool) env('LICENSING_FILING_REQUIRES_LICENCE', true),

    'cache' => [
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
