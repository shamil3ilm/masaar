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
 *   1. If an allowlist is configured, the host must be on it. The platform validates
 *      certificates from one issuer chain, so there is no legitimate reason to
 *      fetch a host nobody has vetted.
 *   2. Otherwise the URL must be https and must not resolve to a private,
 *      loopback, link-local or CGNAT address, over IPv4 or IPv6.
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
        '::/96',            // unspecified, loopback, IPv4-compatible
        '100::/64',         // discard
        '2001::/32',        // Teredo, which tunnels to an address it hides
        '2001:db8::/32',    // documentation
        'fc00::/7',         // unique-local, incl. cloud metadata over IPv6
        'fe80::/10',        // link-local
        'fec0::/10',        // site-local, deprecated
        'ff00::/8',         // multicast
    ];

    /**
     * IPv6 ranges that carry an IPv4 address, and the byte it starts at. The
     * address is judged as the IPv4 one it reaches: ::ffff:10.0.0.1 is
     * 10.0.0.1 to anything that connects to it.
     */
    private const CARRIES_IPV4 = [
        '::ffff:0:0/96' => 12,  // IPv4-mapped
        '64:ff9b::/96' => 12,   // NAT64
        '2002::/16' => 2,       // 6to4
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
        return self::decide($url, $allowlistKey)[0];
    }

    /**
     * The decision, and the address it was made about.
     *
     * Deciding and then letting the HTTP client resolve the name again leaves
     * a gap: a name can answer with a public address to the check and a
     * private one to the connection a moment later. A caller that connects to
     * the returned address - and only to it - closes that gap. Null when there
     * is nothing to pin: the host is a literal address, or an allowlisted name
     * whose operator vouched for it.
     *
     * The third element marks a refusal that may not hold a minute later: the
     * name did not resolve, which a resolver blip causes as readily as a dead
     * domain. A caller that retries can try again; every other refusal is the
     * same answer however often it is asked.
     *
     * @return array{0: string|null, 1: string|null, 2: bool} [why it was refused, the address to connect to, whether the refusal is transient]
     */
    public static function decide(string $url, string $allowlistKey): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return ['URL could not be parsed.', null, false];
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = trim($parts['host'], '[]');

        if ($scheme !== 'https') {
            return ["Scheme '{$scheme}' is not permitted; https only.", null, false];
        }

        $allowed = (array) config($allowlistKey, []);

        if ($allowed !== []) {
            return self::hostMatches($host, $allowed)
                ? [null, null, false]
                : ["Host '{$host}' is not on the allowlist.", null, false];
        }

        return self::decideByAddress($host);
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
     * a private address, and the fetch may use either - IPv6 (AAAA) answers
     * as well as IPv4 (A) ones.
     *
     * @return array{0: string|null, 1: string|null, 2: bool} [reason for refusal, address to pin, transient]
     */
    private static function decideByAddress(string $host): array
    {
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        $addresses = $literal ? [$host] : app(HostResolver::class)->addresses($host);

        if ($addresses === []) {
            // Refused rather than passed through: an unresolvable name here
            // would be resolved again by the fetch itself, which could answer
            // differently. Distinguished from a private address so a DNS
            // outage is not misreported as an attack.
            return ["Host '{$host}' could not be resolved.", null, true];
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                return ["Host '{$host}' resolves to a non-public address ({$address}).", null, false];
            }
        }

        return [null, $literal ? null : $addresses[0], false];
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

        foreach (self::CARRIES_IPV4 as $range => $offset) {
            if (self::inRange($address, $range)) {
                return self::isBlocked((string) inet_ntop(substr((string) inet_pton($address), $offset, 4)));
            }
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the address is in the range, compared as bytes so IPv4 and
     * IPv6 are one rule. An address of the other family is not in it.
     */
    private static function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $addressBytes = @inet_pton($address);
        $subnetBytes = @inet_pton($subnet);

        if ($addressBytes === false || $subnetBytes === false || strlen($addressBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $whole = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;

        if (strncmp($addressBytes, $subnetBytes, $whole) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($addressBytes[$whole]) & $mask) === (ord($subnetBytes[$whole]) & $mask);
    }
}
