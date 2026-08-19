<?php

/**
 * UAE FTA e-Invoicing Configuration (Peppol PINT AE).
 *
 * UAE FTA mandates Peppol PINT AE (UBL 2.1) for e-invoicing.
 * Large taxpayers: Phase 1 — 2026. SMBs: Phase 2 onwards.
 *
 * FTA developer portal: https://www.tax.gov.ae/en/default.aspx
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    */
    'environment' => env('UAE_FTA_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'sandbox' => env('UAE_FTA_SANDBOX_URL', 'https://sandbox-einvoicing.tax.gov.ae/api/v1'),
        'production' => env('UAE_FTA_PRODUCTION_URL', 'https://einvoicing.tax.gov.ae/api/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    */
    // FtaService authenticates with the API key alone. A client id and secret
    // were configured for an OAuth exchange that was never written, so they
    // are absent rather than dormant: a credential an operator can set and
    // that nothing reads is worse than one that is missing.
    'api_key' => env('UAE_FTA_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Peppol Settings
    |--------------------------------------------------------------------------
    */
    'peppol' => [
        'profile_id' => 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0',
        'customization_id' => 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0',
        'country_code' => 'AE',
        'currency' => 'AED',
        'vat_rate' => 0.05,     // 5% UAE VAT
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'max_attempts' => 5,
        'backoff' => [60, 300, 900, 3600, 7200],   // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    */
    'timeout' => env('UAE_FTA_TIMEOUT', 30),
    'connect_timeout' => 10,

];
