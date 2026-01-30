<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | CompliPay API CORS settings for CRM and third-party integrations.
    |
    | For production, set CORS_ALLOWED_ORIGINS in .env to your specific domains:
    | CORS_ALLOWED_ORIGINS=https://app.client.com,https://crm.client.com
    |
    */

    'paths' => ['api/*', 'v1/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => env('CORS_ALLOWED_ORIGINS')
        ? explode(',', env('CORS_ALLOWED_ORIGINS'))
        : ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-API-Key',
        'X-Requested-With',
        'Accept',
        'Origin',
    ],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ],

    'max_age' => 86400, // 24 hours

    'supports_credentials' => false,
];
