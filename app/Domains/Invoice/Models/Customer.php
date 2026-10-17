<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Organization\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone an organization invoices, kept so the next invoice starts filled in.
 *
 * An invoice copies these details when it is written; changing a customer
 * later changes no invoice already issued.
 */
class Customer extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** The invoice form's buyer address keys, in the order it shows them. */
    public const ADDRESS = ['street', 'building_number', 'district', 'city', 'postal_code', 'country_code'];

    /**
     * A UAE buyer's Peppol endpoint: 0235: and ten digits, as the service
     * provider issued it. The three reserved addresses have that shape too.
     */
    public const UAE_ENDPOINT = '/^'.PintAe::SCHEME.':\d{10}$/';

    /** The reserved endpoints, by the UAE invoice form's name for them. */
    private const RESERVED_ENDPOINTS = [
        PintAe::SCHEME.':'.PintAe::BUYER_NOT_YET_ON_EINVOICING => 'not_yet',
        PintAe::SCHEME.':'.PintAe::DEEMED_SUPPLY_BUYER => 'deemed',
        PintAe::SCHEME.':'.PintAe::EXPORT_BUYER => 'export',
    ];

    protected $fillable = [
        'org_id',
        'name',
        'name_ar',
        'vat_number',
        'street',
        'building_number',
        'district',
        'city',
        'postal_code',
        'country_code',
        'email',
        'phone',
        // As the service provider issued it (0235:...), never derived.
        'peppol_endpoint',
        // The emirate, for a UAE buyer.
        'subdivision',
        'archived_at',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /**
     * What the invoice form's buyer fields are filled with.
     *
     * @return array<string, string>
     */
    public function buyerFields(): array
    {
        $fields = [
            'buyer_name' => (string) $this->name,
            'buyer_name_ar' => (string) $this->name_ar,
            'buyer_vat_number' => (string) $this->vat_number,
        ];

        foreach (self::ADDRESS as $key) {
            $fields['buyer_address_'.$key] = (string) $this->{$key};
        }

        return $fields;
    }

    /**
     * What the UAE invoice form's buyer fields are filled with: the same,
     * less the Saudi district, plus the emirate and the buyer's Peppol
     * endpoint: its own, or the reserved address chosen by name, so the form
     * sets that scenario's flag as it does when the address is picked.
     *
     * @return array<string, string>
     */
    public function uaeBuyerFields(): array
    {
        $fields = $this->buyerFields();
        unset($fields['buyer_address_district']);
        $reserved = self::RESERVED_ENDPOINTS[(string) $this->peppol_endpoint] ?? null;

        return [
            ...$fields,
            'buyer_address_country_code' => (string) ($this->country_code ?: 'AE'),
            'buyer_subdivision' => (string) $this->subdivision,
            'buyer_endpoint_choice' => $reserved ?? 'own',
            'buyer_endpoint' => $reserved === null ? (string) $this->peppol_endpoint : '',
        ];
    }
}
