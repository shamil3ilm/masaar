<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CompliPay Integration
    |--------------------------------------------------------------------------
    |
    | CompliPay is the unified compliance gateway that handles all government
    | e-invoicing and tax reporting requirements for GCC and India.
    |
    */

    // Enable/disable CompliPay integration
    'enabled' => env('COMPLIPAY_ENABLED', true),

    // CompliPay API URL
    'url' => env('COMPLIPAY_URL', 'https://api.complipay.com/v1'),

    // API Key for authentication
    'api_key' => env('COMPLIPAY_API_KEY', ''),

    // Webhook secret for verifying callbacks
    'webhook_secret' => env('COMPLIPAY_WEBHOOK_SECRET', ''),

    // Request timeout in seconds
    'timeout' => env('COMPLIPAY_TIMEOUT', 30),

    // Retry configuration
    'retry' => [
        'times' => 3,
        'sleep' => 1000, // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Country-Specific Settings
    |--------------------------------------------------------------------------
    */

    'countries' => [

        // Saudi Arabia - ZATCA
        'SA' => [
            'authority' => 'ZATCA',
            'phases' => ['1', '2'], // Phase 1: Generation, Phase 2: Integration
            'simplified_threshold' => 1000, // SAR
            'requires_qr' => true,
            'requires_uuid' => true,
        ],

        // UAE - FTA
        'AE' => [
            'authority' => 'FTA',
            'simplified_threshold' => 10000, // AED
            'requires_trn' => true,
        ],

        // Bahrain - NBR
        'BH' => [
            'authority' => 'NBR',
            'simplified_threshold' => 500, // BHD
        ],

        // Oman - OTA
        'OM' => [
            'authority' => 'OTA',
            'simplified_threshold' => 500, // OMR
        ],

        // Qatar - GTA
        'QA' => [
            'authority' => 'GTA',
            'vat_implemented' => false,
        ],

        // Kuwait
        'KW' => [
            'authority' => 'KTA',
            'vat_implemented' => false,
        ],

        // India - GST
        'IN' => [
            'authority' => 'GSTN',
            'e_invoice_threshold' => 50000000, // INR 5 Crore turnover
            'e_way_bill_threshold' => 50000, // INR
            'requires_hsn' => true,
            'requires_place_of_supply' => true,
        ],
    ],

];
