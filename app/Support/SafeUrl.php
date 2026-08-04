<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decides whether the application may fetch a URL that came from outside it.
 *
 * Certificate revocation endpoints are read from extensions *inside the
 * certificate being validated*, so whoever supplies the certificate chooses
 * the address. Fetching it unchecked lets them point the server at cloud
 * metadata (169.254.169.254), at internal admin interfaces, or at databases
 * on the private network — a request from inside the trust boundary that they
 * could not make themselves.
 *
 * Two gates, in order:
 *
 *   1. If an allowlist is configured, the host must be on it. Masaar validates
 *      certificates from one issuer chain, so there is no legitimate reason to
 *      fetch a host nobody has vetted.
 *   2. Otherwise the URL must be https and must not resolve to a private,
 *      loopback, link-local or CGNAT address.
 *
 * @see config/security.php
 */
final class SafeUrl
{
    /**
     * Ranges that must never be reachable from a server-side fetch.
     */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',        // "this host"
        '10.0.0.0/8',       // RFC1918 private
        '100.64.0.0/10',    // RFC6598 carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, incl. cloud metadata
        '172.16.0.0/12',    // RFC1918 private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.168.0.0/16',   // RFC1918 private
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved
    ];

    /**
     * Whether the application may fetch this URL.
     */
    public static function isFetchable(string $url, string $allowlistKey): bool
    {
        return self::reject($url, $allowlistKey) === null;
    }

    /**
     * Why the URL may not be fetched, or null if it may.
     *
     * Returning the reason keeps the refusal diagnosable — an operator needs
     * to tell "ZATCA is down" apart from "we refused to call that host".
     */
    public static function reject(string $url, string $allowlistKey): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return 'URL could not be parsed.';
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'];

        if ($scheme !== 'https') {
            return "Scheme '{$scheme}' is not permitted; https only.";
        }

        $allowed = (array) config($allowlistKey, []);

        if ($allowed !== []) {
            return self::hostMatches($host, $allowed)
                ? null
                : "Host '{$host}' is not on the allowlist.";
        }

        return self::rejectByAddress($host);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function hostMatches(string $host, array $allowed): bool
    {
        $host = strtolower($host);

        foreach ($allowed as $entry) {
            $entry = strtolower(trim((string) $entry));

            if ($entry === '') {
                continue;
            }

            // A leading dot allows subdomains of that zone, nothing else.
            if (str_starts_with($entry, '.')) {
                if (str_ends_with($host, $entry) || $host === ltrim($entry, '.')) {
                    return true;
                }

                continue;
            }

            if ($host === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the host and checks every address it answers with.
     *
     * All of them are checked because a name can resolve to both a public and
     * a private address, and the fetch may use either.
     *
     * @return string|null Reason for refusal, or null if the host is fetchable.
     */
    private static function rejectByAddress(string $host): ?string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            // Refused rather than passed through: an unresolvable name here
            // would be resolved again by the fetch itself, which could answer
            // differently. Distinguished from a private address so a DNS
            // outage is not misreported as an attack.
            return "Host '{$host}' could not be resolved.";
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                return "Host '{$host}' resolves to a non-public address ({$address}).";
            }
        }

        return null;
    }

    private static function isBlocked(string $address): bool
    {
        // Anything not a normal public IPv4/IPv6 address is refused outright.
        if (! filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            return true;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $addressLong = ip2long($address);
        $subnetLong = ip2long($subnet);

        if ($addressLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($addressLong & $mask) === ($subnetLong & $mask);
    }
}
