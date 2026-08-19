<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Fetches a URL that the application did not choose.
 *
 * SafeUrl decides whether an address may be contacted; this performs the
 * request under limits that hold even when the answer is hostile.
 *
 * Redirects are refused rather than followed. That is the point: SafeUrl
 * validates the address it is given, and a fetch that follows redirects can be
 * sent anywhere afterwards — an allowlisted host answering 302 to
 * 169.254.169.254 defeats the check entirely. A revocation endpoint has no
 * legitimate reason to redirect.
 *
 * @see SafeUrl for which addresses are permitted
 * @see config/security.php for the limits
 */
final class SafeFetch
{
    /**
     * Retrieve a URL, or null if it could not be fetched safely.
     *
     * Returns null rather than throwing because the callers are revocation
     * checks, where "could not determine" is a real answer distinct from
     * "not revoked" — and one they already handle.
     */
    public static function get(string $url, string $allowlistKey): ?string
    {
        $reason = SafeUrl::reject($url, $allowlistKey);

        if ($reason !== null) {
            Log::warning('Outbound fetch refused', ['url' => $url, 'reason' => $reason]);

            return null;
        }

        $maxBytes = (int) config('security.fetch.max_bytes', 5 * 1024 * 1024);

        $stream = @fopen($url, 'rb', false, stream_context_create(self::transportPolicy()));

        if ($stream === false) {
            Log::warning('Outbound fetch failed to open', ['url' => $url]);

            return null;
        }

        try {
            $status = self::statusOf($http_response_header ?? []);

            if ($status !== 200) {
                Log::warning('Outbound fetch returned an unusable status', [
                    'url' => $url,
                    'status' => $status,
                ]);

                return null;
            }

            // Read one byte past the cap so an oversized body is detected
            // rather than silently truncated into a valid-looking answer.
            $body = stream_get_contents($stream, $maxBytes + 1);

            if ($body === false) {
                return null;
            }

            if (strlen($body) > $maxBytes) {
                Log::warning('Outbound fetch exceeded the size limit', [
                    'url' => $url,
                    'limit_bytes' => $maxBytes,
                ]);

                return null;
            }

            return $body;
        } finally {
            fclose($stream);
        }
    }

    /**
     * The stream options every outbound fetch runs under.
     *
     * Exposed so the policy can be asserted directly. The alternative is a
     * live server and a real redirect, which proves the same thing at the cost
     * of a socket in the test suite.
     *
     * @return array{http: array<string, mixed>, ssl: array<string, bool>}
     */
    public static function transportPolicy(): array
    {
        return [
            'http' => [
                'method' => 'GET',
                'timeout' => (int) config('security.fetch.timeout_seconds', 10),
                'user_agent' => 'Masaar-ZATCA-Client/1.0',
                // A redirect would leave SafeUrl's decision behind.
                'follow_location' => 0,
                'max_redirects' => 0,
                // Read the status line ourselves rather than letting a 3xx or
                // 4xx surface as a warning and an empty body.
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
    }

    /**
     * @param  list<string>  $headers
     */
    private static function statusOf(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }
}
