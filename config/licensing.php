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

    /*
    | A customer's allowances unless a platform admin sets its own: invoices a
    | month and API calls a day per licence. -1 is unlimited, the platform's
    | default for now. The per-minute call cap and the per-device submission
    | caps are technical safety limits and are not allowances.
    */
    'allowances' => [
        'invoices_per_month' => (int) env('LICENSING_MONTHLY_INVOICES', -1),
        'calls_per_day' => (int) env('LICENSING_DAILY_API_CALLS', -1),
    ],

    /*
    | The API keys an organization's administrators make in the portal
    | (Integrations): how many it may hold at once, and how many it may make
    | in an hour, so a stolen session cannot mint credentials by the hundred.
    */
    'api_keys' => [
        'max_per_organization' => 20,
        'created_per_hour' => 10,
    ],

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
        // An allowance that is set warns the customer's administrators as it
        // is approached and reached (AllowanceWarnings), once each a period.
        'quota_warning_percent' => [80, 100],
        'rate_limit_notify' => true,
    ],
];
