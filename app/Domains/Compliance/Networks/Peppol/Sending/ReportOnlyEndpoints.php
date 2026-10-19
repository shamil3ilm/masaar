<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Sending;

use App\Domains\Compliance\Networks\Peppol\Receiving\InboundDocuments;

/**
 * Buyer endpoints a country reserves for documents that are reported to the
 * authority and never exchanged: there is no buyer's provider to deliver to,
 * so no delivery confirmation ever comes (a deemed supply, a buyer not yet
 * on e-invoicing, an export buyer with no identifier).
 *
 * Each country lists its own under peppol.countries.<code>.report_only_endpoints,
 * so the network names none.
 */
final class ReportOnlyEndpoints
{
    public function contains(?string $endpoint): bool
    {
        if ($endpoint === null || trim($endpoint) === '') {
            return false;
        }

        return in_array(InboundDocuments::endpoint($endpoint), $this->all(), true);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $endpoints = [];

        foreach ((array) config('peppol.countries', []) as $country) {
            foreach ((array) ($country['report_only_endpoints'] ?? []) as $endpoint) {
                $endpoints[] = (string) $endpoint;
            }
        }

        return $endpoints;
    }
}
