<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;

/**
 * A buyer's Peppol endpoint as the UAE invoice form and a kept customer
 * know it (Presentation::buyerFields, Catalogue::rememberBuyer).
 */
final class BuyerFields
{
    /**
     * A UAE buyer's Peppol endpoint: 0235: and ten digits, as the service
     * provider issued it. The three reserved addresses have that shape too.
     */
    public const ENDPOINT = '/^'.PintAe::SCHEME.':\d{10}$/';

    /** The reserved endpoints, by the UAE invoice form's name for them. */
    public const RESERVED_ENDPOINTS = [
        PintAe::SCHEME.':'.PintAe::BUYER_NOT_YET_ON_EINVOICING => 'not_yet',
        PintAe::SCHEME.':'.PintAe::DEEMED_SUPPLY_BUYER => 'deemed',
        PintAe::SCHEME.':'.PintAe::EXPORT_BUYER => 'export',
    ];

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
