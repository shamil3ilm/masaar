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
    |
    | Deliberately absent. There was a 'peppol' block here declaring
    | profile_id and customization_id as generic Peppol BIS Billing 3.0, plus
    | a country code, currency and VAT rate. Nothing read any of it —
    | config('fta.peppol') had no callers — and the two identifiers were wrong:
    | the UAE requires PINT AE, its own national profile, not generic BIS.
    |
    | FtaXmlBuilder holds the correct values as constants
    | (CUSTOMIZATION = urn:peppol:pint:billing-1@ae-1) and FtaComplianceSpecTest
    | asserts them by value. Configuration that is never read is worse than
    | absent: it invites someone to correct an identifier here and watch the
    | document keep emitting the old one.
    |
    | If these become configurable, the builder has to read them and the spec
    | test has to move with them.
    */

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
