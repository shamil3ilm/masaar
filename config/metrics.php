<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Metrics Endpoint Access Control
    |--------------------------------------------------------------------------
    |
    | The Prometheus endpoint exposes application version, environment and
    | business telemetry (invoice counts, submission states, ZATCA error rates,
    | queue depth). None of that is public information, so access is closed by
    | default and must be opened deliberately — the same posture as CORS.
    |
    | A request is admitted when its source IP is allowlisted OR it presents the
    | bearer token. With neither configured the endpoint is unreachable in
    | production; outside production it stays open so local scrapes work.
    |
    */

    'token' => env('METRICS_TOKEN'),

    /*
    | Comma-separated source IPs permitted to scrape, e.g. the Prometheus
    | container address. Exact matches only — no CIDR expansion.
    |
    | CAVEAT: this app does not configure TrustProxies, so behind a reverse
    | proxy Request::ip() reports the PROXY's address, not the client's.
    | Allowlisting it would therefore admit every request that reaches the
    | proxy. Prefer METRICS_TOKEN unless the scraper connects directly.
    */
    'allowed_ips' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('METRICS_ALLOWED_IPS', '')))
    )),

];
