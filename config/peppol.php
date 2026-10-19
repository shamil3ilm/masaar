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
    | Off unless a deployment files in that country. The default only: a
    | platform admin's switch on the console's Countries page overrides it,
    | as ZATCA_ENABLED is overridden for Saudi Arabia. While AE is off an
    | organization cannot be registered there and nothing new is issued; what
    | is already issued keeps being sent.
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
        // Sending runs on its own queue and worker (laravel-worker-peppol),
        // apart from ZATCA's, so neither country's provider delays the other.
        'queue' => env('PEPPOL_QUEUE') ?: 'peppol',
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    |
    | Empty: the provider at asp.base_url, or none while that is empty.
    | "simulator": the service-provider simulator below, which plays a
    | provider inside this application so the whole flow can be exercised
    | with no provider contracted. Production refuses to boot with it.
    |
    */
    'gateway' => env('PEPPOL_GATEWAY') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Service-provider simulator (local and staging only)
    |--------------------------------------------------------------------------
    |
    | Validates every document with the official rules (artefacts_path and
    | xslt_jar below), then calls back through the same signed webhooks a
    | provider would, at callback_url (APP_URL when empty). Callbacks run on
    | the Peppol queue, so a worker must be running.
    |
    | step_seconds is the pause between one callback and the next: 0 plays
    | the lifecycle at once, 10 or so lets a person watch it in the portal.
    |
    | outcomes: buyer endpoints that play something other than a delivery,
    | for demos and tests. unknown_receiver: no such participant on the
    | network. unavailable: the first attempt gets no answer, the retry is
    | taken. answer_lost: the first attempt is taken but its answer lost, so
    | the retry must not file it twice. rejected: the buyer's provider
    | refuses it. report_only: reported to the authority, never exchanged -
    | the UAE's reserved endpoints for a buyer not yet on e-invoicing, a
    | deemed supply and an export (UAE Electronic Invoicing Guidelines v1.1,
    | p23, p26, p28).
    |
    */
    'simulator' => [
        'step_seconds' => max(0, (int) env('PEPPOL_SIMULATOR_STEP_SECONDS', 0)),
        'callback_url' => env('PEPPOL_SIMULATOR_CALLBACK_URL') ?: null,
        'outcomes' => [
            '0235:1999999901' => 'unknown_receiver',
            '0235:1999999902' => 'unavailable',
            '0235:1999999903' => 'answer_lost',
            '0235:1999999904' => 'rejected',
            '0235:9900000097' => 'report_only',
            '0235:9900000098' => 'report_only',
            '0235:9900000099' => 'report_only',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conformance artefacts (tests and the simulator)
    |--------------------------------------------------------------------------
    |
    | The official PINT schematrons and an XSLT 2 processor jar. The
    | conformance tests are skipped while either is unset, and the simulator
    | sends nothing: it will not take a document it cannot judge.
    |
    */
    'artefacts_path' => env('PEPPOL_ARTEFACTS_PATH') ?: null,
    'xslt_jar' => env('PEPPOL_XSLT_JAR') ?: null,

];
