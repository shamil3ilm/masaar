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
    | Peppol identifiers
    |--------------------------------------------------------------------------
    |
    | Not configurable. The UAE requires PINT AE, its own national Peppol
    | profile, and FtaXmlBuilder holds the identifiers as constants
    | (CUSTOMIZATION = urn:peppol:pint:billing-1@ae-1) with
    | FtaComplianceSpecTest asserting them by value.
    |
    | Making them configurable means the builder reads them and the spec test
    | moves with them; a key here that the builder does not read is a setting
    | that silently does nothing.
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
