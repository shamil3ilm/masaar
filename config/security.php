<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound Fetch Allowlists
    |--------------------------------------------------------------------------
    |
    | Hosts the application may contact when the address came from data it did
    | not choose. Certificate revocation endpoints are read from extensions
    | inside the certificate being validated, so whoever supplies the
    | certificate picks the address.
    |
    | Masaar validates certificates from one issuer chain, so listing ZATCA's
    | endpoints here is both sufficient and the strongest control available.
    | An entry beginning with a dot allows that zone's subdomains.
    |
    | Leave empty and the weaker fallback applies: https only, and the host
    | must not resolve to a private, loopback, link-local or CGNAT address.
    |
    */

    'revocation_hosts' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('REVOCATION_ALLOWED_HOSTS', '')))
    )),

    /*
    |--------------------------------------------------------------------------
    | Outbound Fetch Limits
    |--------------------------------------------------------------------------
    |
    | A CRL is tens of kilobytes. Caps stop a hostile or broken endpoint from
    | holding a worker open or exhausting memory.
    |
    */

    'fetch' => [
        'timeout_seconds' => (int) env('OUTBOUND_FETCH_TIMEOUT', 10),
        'max_bytes' => (int) env('OUTBOUND_FETCH_MAX_BYTES', 5 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Key Pepper
    |--------------------------------------------------------------------------
    |
    | Mixed into API key hashes, so a leaked api_keys table cannot be attacked
    | offline without also compromising this configuration. Keys carry 40
    | characters of entropy, which is the primary control; this is defence in
    | depth.
    |
    | Setting or changing it invalidates every key issued before the change,
    | which is a deliberate rotation and not something to do casually.
    |
    */

    'api_key_pepper' => env('API_KEY_PEPPER', ''),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Per tenant, per minute, per cost band. A tenant is the unit that pays and
    | the unit that can be noisy; keying on the user let one customer multiply
    | its share by opening more sessions, and left API-key integrations keyed
    | on an IP they could rotate.
    |
    | Bands exist because endpoints are not equally expensive. Submitting an
    | invoice signs it and calls ZATCA; reading a status does not. Route
    | fragments are matched against the route definition, not the request URI,
    | so a path parameter cannot move a request into a cheaper band.
    |
    */

    'rate_limits' => [

        'default' => (int) env('RATE_LIMIT_DEFAULT', 60),

        // Signing and an outbound call to the authority.
        'submission' => (int) env('RATE_LIMIT_SUBMISSION', 30),

        // Certificate issuance: rare, expensive, and security sensitive.
        'onboarding' => (int) env('RATE_LIMIT_ONBOARDING', 5),

        // Cheap reads.
        'read' => (int) env('RATE_LIMIT_READ', 120),

        // No tenant to attribute the traffic to, and the most abused surface.
        'anonymous' => (int) env('RATE_LIMIT_ANONYMOUS', 20),

        'bands' => [
            'submission' => ['pipeline/submit', 'compliance/sa/submit', 'compliance/ae/submit'],
            'onboarding' => ['onboarding', 'ccsid', 'pcsid'],
            'read' => ['status', 'health', 'dashboard'],
        ],
    ],

];
