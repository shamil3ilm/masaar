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
    | The platform validates certificates from one issuer chain, so listing ZATCA's
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
    | Hosts a tenant's webhook may point at.
    |
    | A webhook is a URL the tenant chooses and the platform calls, so it gets
    | the same check as a revocation endpoint. Empty is the usual setting -
    | customers' endpoints are not known in advance - and means https only,
    | resolving only to public addresses. A deployment that serves known ERPs
    | can name them here and refuse everything else.
    */
    'webhook_hosts' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('WEBHOOK_ALLOWED_HOSTS', '')))
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

        // The ladder below is ordered by what a request costs, so a band is
        // never tighter than the fallback it would otherwise drop through to.
        // Requests a minute, per tenant, per band.

        // Ordinary API traffic. This platform is built to be integrated with,
        // and sixty a minute is one request a second, which an ERP sync loop
        // exceeds immediately - and a 429 drawn from a shared bucket is hard
        // for a client to attribute to anything.
        'default' => (int) env('RATE_LIMIT_DEFAULT', 300),

        // Signing and an outbound call to the authority.
        //
        // Must agree with fatoora.rate_limits.per_minute, the organization's
        // ceiling that SubmissionGuard enforces on the same traffic: two
        // keys, one thing, set together or the tighter one silently wins.
        // Each device is held to its own, lower figure inside
        // (fatoora.rate_limits.device_per_minute), which the edge cannot see.
        //
        // Three hundred is a chain of shops' ceiling: two and a half devices
        // at their own cap, or many quieter tills. No higher than default,
        // which this band may not exceed. It is deliberately a platform
        // ceiling and not an ambition: a limit set above what ZATCA's own
        // API accepts does not buy throughput, it moves the refusal from a
        // cheap local 429 to a failed submission against the twenty-four hour
        // reporting deadline. Confirm the authority's published figure
        // before production and keep this under it.
        'submission' => (int) env('RATE_LIMIT_SUBMISSION', 300),

        // Certificate issuance: rare, expensive, and security sensitive. The
        // tightest band on purpose - an OTP is spent whether the request
        // succeeds or not.
        'onboarding' => (int) env('RATE_LIMIT_ONBOARDING', 5),

        // Cheap reads: a status poll or a health check costs almost nothing,
        // so this is the most generous band rather than a mid-range one.
        'read' => (int) env('RATE_LIMIT_READ', 600),

        // No tenant to attribute the traffic to, and the most abused surface:
        // login and register fall here. Applied as the tighter of this and the
        // band, see RateLimitApi::handle().
        'anonymous' => (int) env('RATE_LIMIT_ANONYMOUS', 20),

        // Matched against the route's URI, which carries no method - so a
        // pattern that caught GET /invoices would hand POST /invoices the same
        // budget. Patterns stay narrow for that reason; anything they do not
        // match draws on 'default'.
        'bands' => [
            'submission' => ['pipeline/submit', 'pipeline/batch', 'compliance/sa/submit', 'compliance/ae/submit'],
            'onboarding' => ['onboarding', 'ccsid', 'pcsid'],
            'read' => ['status', 'health', 'dashboard'],
        ],
    ],

];
