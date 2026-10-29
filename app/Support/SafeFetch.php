<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

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
 * The connection goes to the address SafeUrl approved and no other: letting
 * the client resolve the name again would let it answer with a public
 * address to the check and a private one to the fetch. The body is read in
 * pieces and abandoned once it passes the size limit, so a hostile server
 * cannot make the process hold more than that.
 *
 * @see SafeUrl for which addresses are permitted
 * @see config/security.php for the limits
 */
final class SafeFetch
{
    private const CHUNK_BYTES = 8192;

    /**
     * Retrieve a URL, or null if it could not be fetched safely.
     *
     * Returns null rather than throwing because the callers are revocation
     * checks, where "could not determine" is a real answer distinct from
     * "not revoked" — and one they already handle.
     *
     * $plainHttp also admits an http URL, for content whose signature the
     * caller verifies: a revocation list is published at an HTTP address.
     * Its host is held to the same rules as over https.
     */
    public static function get(string $url, string $allowlistKey, bool $plainHttp = false): ?string
    {
        $checked = $plainHttp && str_starts_with(strtolower($url), 'http://')
            ? 'https://'.substr($url, strlen('http://'))
            : $url;

        [$reason, $address] = SafeUrl::decide($checked, $allowlistKey);

        if ($reason !== null) {
            Log::warning('Outbound fetch refused', ['url' => $url, 'reason' => $reason]);

            return null;
        }

        try {
            $response = Http::withOptions(self::options($url, $address))->get($url)->toPsrResponse();
        } catch (\Throwable $e) {
            Log::warning('Outbound fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            Log::warning('Outbound fetch returned an unusable status', [
                'url' => $url,
                'status' => $response->getStatusCode(),
            ]);

            return null;
        }

        return self::bounded($response, $url);
    }

    /**
     * The client options every outbound fetch runs under, connecting to
     * $address when SafeUrl named one.
     *
     * Exposed so it can be asserted directly, and so RedirectPolicyTest can
     * apply it to a real server and a real redirect. Both are worth having: the
     * shape assertion catches an option being dropped, and only the live one
     * catches an option the client ignores.
     *
     * @return array<string, mixed>
     */
    public static function options(string $url, ?string $address): array
    {
        $timeout = (int) config('security.fetch.timeout_seconds', 10);

        $options = [
            // A redirect would leave SafeUrl's decision behind.
            'allow_redirects' => false,
            // Read as it arrives, so the size limit applies while reading.
            'stream' => true,
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'verify' => true,
            'headers' => ['User-Agent' => Brand::userAgent('Client')],
        ];

        if ($address === null) {
            return $options;
        }

        $parts = parse_url($url);
        $port = $parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443);
        $target = str_contains($address, ':') ? '['.$address.']' : $address;

        return $options + ['curl' => [CURLOPT_RESOLVE => [($parts['host'] ?? '').':'.$port.':'.$target]]];
    }

    /**
     * The body, or null once it is longer than the limit: refused on its
     * declared length before reading, and on what has arrived while reading.
     */
    private static function bounded(ResponseInterface $response, string $url): ?string
    {
        $maxBytes = (int) config('security.fetch.max_bytes', 5 * 1024 * 1024);
        $declared = $response->getHeaderLine('Content-Length');
        $stream = $response->getBody();
        $body = '';

        try {
            if ($declared !== '' && (int) $declared > $maxBytes) {
                return self::tooLarge($url, $maxBytes);
            }

            while (! $stream->eof()) {
                $body .= $stream->read(self::CHUNK_BYTES);

                if (strlen($body) > $maxBytes) {
                    return self::tooLarge($url, $maxBytes);
                }
            }

            return $body;
        } finally {
            $stream->close();
        }
    }

    private static function tooLarge(string $url, int $maxBytes): null
    {
        Log::warning('Outbound fetch exceeded the size limit', [
            'url' => $url,
            'limit_bytes' => $maxBytes,
        ]);

        return null;
    }
}
