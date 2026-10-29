<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The addresses a name answers with: its A records and its AAAA records.
 *
 * Both, because a fetch may connect over either family, so a check that
 * reads only one leaves the other unexamined. A class of its own so a test
 * can answer for DNS.
 */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $ipv4 = gethostbynamel($host) ?: [];

        // Silenced: a name with no AAAA record, or a resolver that will not
        // answer, warns rather than returning an empty list.
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
