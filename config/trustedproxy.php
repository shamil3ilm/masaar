<?php

/**
 * Proxies whose forwarded headers are believed.
 *
 * Illuminate\Http\Middleware\TrustProxies reads trustedproxy.proxies on every
 * request, and nothing set it. Behind a reverse proxy every request then
 * seemed to come from the proxy: all clients shared one rate-limit bucket and
 * every audit row named the proxy's address.
 *
 * Comma-separated addresses or CIDR ranges. Unset trusts nothing, which is
 * right for a deployment that faces clients directly. '*' trusts whoever
 * connects and is only safe when nothing but the proxy can reach the app.
 */
return [

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
