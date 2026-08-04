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

];
