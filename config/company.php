<?php

/**
 * Who operates this deployment, as the public pages, the privacy notice and
 * the terms name it.
 *
 * Empty values are left out of the pages rather than printed blank. Fill
 * them before the first customer signs up: the privacy notice has to say who
 * the controller is and how to reach them.
 */
return [

    // The registered legal name, as on the commercial registration.
    // Empty in the templates: until it is filled in, the brand's legal name
    // (config/brand.php) stands in.
    'legal_name' => env('COMPANY_LEGAL_NAME') ?: (require __DIR__.'/brand.php')['legal_name'],

    'cr_number' => env('COMPANY_CR_NUMBER'),

    'vat_number' => env('COMPANY_VAT_NUMBER'),

    'address' => env('COMPANY_ADDRESS'),

    // Where customers and the public write to: sales, support before an
    // account exists, and privacy requests.
    'email' => env('CONTACT_EMAIL'),

    'phone' => env('CONTACT_PHONE'),

    // Raised when the privacy notice changes, as SIGNUP_TERMS_VERSION is
    // for the terms.
    'privacy_version' => env('PRIVACY_VERSION', '1'),

];
