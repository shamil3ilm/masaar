<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Invoice\Models\Customer;

/**
 * A kept customer as the UAE invoice form knows a buyer: its Peppol
 * endpoint and emirate beside what every form fills.
 */
final class BuyerFields
{
    /**
     * A UAE buyer's Peppol endpoint: 0235: and ten digits, as the service
     * provider issued it. The three reserved addresses have that shape too.
     */
    public const ENDPOINT = '/^'.PintAe::SCHEME.':\d{10}$/';

    /** The reserved endpoints, by the UAE invoice form's name for them. */
    private const RESERVED_ENDPOINTS = [
        PintAe::SCHEME.':'.PintAe::BUYER_NOT_YET_ON_EINVOICING => 'not_yet',
        PintAe::SCHEME.':'.PintAe::DEEMED_SUPPLY_BUYER => 'deemed',
        PintAe::SCHEME.':'.PintAe::EXPORT_BUYER => 'export',
    ];

    /**
     * What the UAE invoice form's buyer fields are filled with: the same,
     * less the district and the other identifier, plus the emirate and the
     * buyer's Peppol endpoint: its own, or the reserved address chosen by name, so the form
     * sets that scenario's flag as it does when the address is picked.
     *
     * @return array<string, string>
     */
    public static function of(Customer $customer): array
    {
        $fields = $customer->buyerFields();
        unset($fields['buyer_address_district'], $fields['buyer_id_scheme'], $fields['buyer_id']);
        $reserved = self::RESERVED_ENDPOINTS[(string) $customer->peppol_endpoint] ?? null;

        return [
            ...$fields,
            'buyer_address_country_code' => (string) ($customer->country_code ?: 'AE'),
            'buyer_subdivision' => (string) $customer->subdivision,
            'buyer_endpoint_choice' => $reserved ?? 'own',
            'buyer_endpoint' => $reserved === null ? (string) $customer->peppol_endpoint : '',
        ];
    }

    /**
     * A UAE invoice's buyer endpoint and emirate, as a customer keeps them
     * (Catalogue::rememberBuyer). An endpoint in another Peppol scheme (a
     * buyer abroad) is not the shape a customer keeps, and is left as it was.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, string>
     */
    public static function kept(array $invoice): array
    {
        $endpoint = (string) ($invoice['buyer_endpoint'] ?? '');
        $subdivision = (string) ($invoice['buyer_subdivision'] ?? '');

        return array_filter([
            'peppol_endpoint' => preg_match(self::ENDPOINT, $endpoint) === 1 ? $endpoint : '',
            'subdivision' => $subdivision,
        ], fn (string $value): bool => $value !== '');
    }
}
