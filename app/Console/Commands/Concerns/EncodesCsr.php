<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * The csr field ZATCA's onboarding API expects.
 *
 * Base64 of the whole PEM, headers included. Two other readings were in use
 * and both are refused with "Invalid Request", which says nothing about which
 * part of the request was wrong:
 *
 *   the PEM's inner base64, headers stripped   -> HTTP 400
 *   base64 of that stripped content            -> HTTP 400
 *   base64 of the PEM as it sits on disk       -> HTTP 200, ISSUED
 *
 * Confirmed against the developer-portal sandbox, which is also where the
 * difference is cheapest to observe.
 */
trait EncodesCsr
{
    private function encodeCsrForZatca(string $pem): string
    {
        $pem = trim($pem);

        // A CSR that is not PEM is already the raw DER the SDK writes; base64
        // is what the field carries either way.
        return base64_encode($pem);
    }
}
