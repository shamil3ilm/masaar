<?php

/**
 * Peppol e-invoicing (PINT), per country.
 *
 * The platform sends and receives through an accredited service provider:
 * it builds the document, the provider carries it over the network and
 * reports it to the authority. Which provider is a deployment's choice, so
 * nothing here names one.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Countries
    |--------------------------------------------------------------------------
    |
    | Off unless a deployment files in that country. While AE is off an
    | organization cannot be registered there.
    |
    */
    'countries' => [
        'AE' => [
            'enabled' => (bool) env('PEPPOL_AE_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accredited service provider
    |--------------------------------------------------------------------------
    |
    | Empty base_url: no provider is connected, and documents wait to be
    | downloaded and uploaded by hand. Set it, and the credentials beside it
    | are required before production serves.
    |
    */
    'asp' => [
        'base_url' => env('PEPPOL_ASP_BASE_URL') ?: null,
        'client_id' => env('PEPPOL_ASP_CLIENT_ID') ?: null,
        'client_secret' => env('PEPPOL_ASP_CLIENT_SECRET') ?: null,
        // Signs the provider's status and inbound callbacks.
        'webhook_secret' => env('PEPPOL_ASP_WEBHOOK_SECRET') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Conformance artefacts (tests only)
    |--------------------------------------------------------------------------
    |
    | The official PINT schematrons and an XSLT 2 processor jar. The
    | conformance test is skipped while either is unset.
    |
    */
    'artefacts_path' => env('PEPPOL_ARTEFACTS_PATH') ?: null,
    'xslt_jar' => env('PEPPOL_XSLT_JAR') ?: null,

];
