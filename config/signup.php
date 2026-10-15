<?php

/**
 * Businesses signing themselves up.
 *
 * A business registers its account and its ZATCA details, verifies its email
 * address, and waits: a platform admin reviews the company and assigns its
 * plan before it can onboard with ZATCA or issue.
 */
return [

    // Off for a deployment where the platform creates every organization
    // itself; /register then answers 404.
    'enabled' => (bool) env('SIGNUP_ENABLED', true),

    // The terms a business accepts at sign-up. The version is stored with the
    // acceptance, so a change of terms is a new version and a record of who
    // accepted which.
    'terms_url' => env('SIGNUP_TERMS_URL'),
    'terms_version' => env('SIGNUP_TERMS_VERSION', '1'),

];
