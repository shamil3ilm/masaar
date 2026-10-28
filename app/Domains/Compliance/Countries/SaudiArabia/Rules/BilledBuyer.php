<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * An organization billed on a Saudi invoice: how it is identified, and
 * whether its national address is complete.
 */
final class BilledBuyer
{
    /**
     * @return array{buyer_vat_number: string|null, buyer_id: string|null, buyer_id_scheme: string|null}
     *
     * @throws DomainException when it has neither
     */
    public static function buyerIdentity(Organization $buyer): array
    {
        // A registration outside the Kingdom is not a Saudi VAT number: BT-48
        // takes only the 15-digit 3...3 form (BR-KSA-44), so a foreign tax
        // number identifies the buyer as another ID instead.
        if (filled($buyer->vat_number) && strtoupper((string) ($buyer->country ?: 'SA')) !== 'SA') {
            return ['buyer_vat_number' => null, 'buyer_id' => preg_replace('/[^A-Za-z0-9]/', '', (string) $buyer->vat_number), 'buyer_id_scheme' => 'OTH'];
        }

        if (filled($buyer->vat_number)) {
            return ['buyer_vat_number' => (string) $buyer->vat_number, 'buyer_id' => null, 'buyer_id_scheme' => null];
        }

        if (filled($buyer->cr_number)) {
            return ['buyer_vat_number' => null, 'buyer_id' => (string) $buyer->cr_number, 'buyer_id_scheme' => 'CRN'];
        }

        throw new DomainException($buyer->name.' has neither a VAT number nor a CR number, so no invoice was issued. Try again once the company has added one.');
    }

    /**
     * @throws DomainException naming what is missing
     */
    public static function assertNationalAddress(Organization $buyer): void
    {
        if (($buyer->country ?: 'SA') !== 'SA') {
            return;
        }

        $missing = array_keys(array_filter([
            'street' => $buyer->street,
            'building number' => $buyer->building_number,
            'postal code' => $buyer->postal_code,
            'city' => $buyer->city,
            'district' => $buyer->district,
        ], fn ($value): bool => blank($value)));

        if ($missing !== []) {
            throw new DomainException("{$buyer->name}'s national address has no ".implode(', ', $missing).', so no invoice was issued. Try again once the company has completed it.');
        }
    }
}
