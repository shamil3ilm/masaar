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
    | API Key Settings
    |--------------------------------------------------------------------------
    |
    | Settings for API key generation and validation.
    |
    */
    'api_key' => [
        'prefix' => env('LICENSE_API_KEY_PREFIX', 'cpay_'),
        'length' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting fallback settings when Redis is unavailable.
    |
    */
    'rate_limiting' => [
        'cache_driver' => env('LICENSE_RATE_LIMIT_DRIVER', 'redis'),
        'cleanup_interval_hours' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier Defaults
    |--------------------------------------------------------------------------
    |
    | Default limits for each license tier. These can be overridden
    | per-license in the database.
    |
    */
    'tiers' => [
        'starter' => [
            'max_invoices_per_month' => 100,
            'max_api_calls_per_day' => 1000,
            'max_api_calls_per_minute' => 10,
            'max_organizations' => 1,
            'features' => [
                'simplified_invoices',
                'basic_reports',
            ],
        ],
        'professional' => [
            'max_invoices_per_month' => 1000,
            'max_api_calls_per_day' => 10000,
            'max_api_calls_per_minute' => 60,
            'max_organizations' => 5,
            'features' => [
                'simplified_invoices',
                'standard_invoices',
                'credit_notes',
                'debit_notes',
                'advanced_reports',
                'webhook_notifications',
            ],
        ],
        'enterprise' => [
            'max_invoices_per_month' => 10000,
            'max_api_calls_per_day' => 100000,
            'max_api_calls_per_minute' => 300,
            'max_organizations' => 50,
            'features' => [
                'simplified_invoices',
                'standard_invoices',
                'credit_notes',
                'debit_notes',
                'advanced_reports',
                'webhook_notifications',
                'priority_support',
                'custom_integrations',
                'batch_processing',
            ],
        ],
        'unlimited' => [
            'max_invoices_per_month' => -1, // -1 means unlimited
            'max_api_calls_per_day' => -1,
            'max_api_calls_per_minute' => 1000,
            'max_organizations' => -1,
            'features' => [
                'simplified_invoices',
                'standard_invoices',
                'credit_notes',
                'debit_notes',
                'advanced_reports',
                'webhook_notifications',
                'priority_support',
                'custom_integrations',
                'batch_processing',
                'dedicated_support',
                'sla_guarantee',
                'white_label',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Tracking
    |--------------------------------------------------------------------------
    |
    | Settings for usage metering and analytics.
    |
    */
    'usage' => [
        'retention_days' => env('LICENSE_USAGE_RETENTION_DAYS', 365),
        'aggregation_enabled' => true,
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

    /*
    |--------------------------------------------------------------------------
    | Admin Settings
    |--------------------------------------------------------------------------
    |
    | Settings for license administration.
    |
    */
    'admin' => [
        'super_admin_bypass' => env('LICENSE_ADMIN_BYPASS', false),
        'require_activation' => env('LICENSE_REQUIRE_ACTIVATION', true),
    ],
];
