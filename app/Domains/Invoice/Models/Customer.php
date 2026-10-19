<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use RecordsActors;
    use SoftDeletes;

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
        // The identifier a buyer without a VAT number is known by, and the
        // register it is from (BT-46, BT-46-1).
        'buyer_id',
        'buyer_id_scheme',
        'street',
        'building_number',
        'district',
        'city',
        'postal_code',
        'country_code',
        'email',
        'phone',
        // The terms its invoices are usually paid on, filled into a new one.
        'payment_terms',
        // As the service provider issued it (0235:...), never derived.
        'peppol_endpoint',
        // The emirate, for a UAE buyer.
        'subdivision',
        // The zero rating this buyer is eligible for, where the rate depends
        // on who buys (a citizen's healthcare or education).
        'exempt_code',
        'archived_at',
    ];

    /** Computed from deleted_at for the unique indexes (0960); never written. */
    protected $hidden = ['not_deleted'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /**
     * Who made, changed and removed it, how it arrived and through which
     * licence. Archiving is not removal: an archived customer is kept in the
     * list and left out of the invoice form; a removed one is gone from both
     * until it is brought back.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return [
            'created_by' => 'created_by',
            'updated_by' => 'updated_by',
            'deleted_by' => 'deleted_by',
            'created_via' => 'created_via',
            'license_id' => 'license_id',
        ];
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
            'buyer_id_scheme' => (string) $this->buyer_id_scheme,
            'buyer_id' => (string) $this->buyer_id,
        ];

        foreach (self::ADDRESS as $key) {
            $fields['buyer_address_'.$key] = (string) $this->{$key};
        }

        // Not a buyer field, and filled the same way: the terms this
        // customer is usually paid on.
        $fields['payment_terms'] = (string) $this->payment_terms;

        // Not a field either: the zero rating the form starts this
        // customer's lines in (resources/js/invoice-vat.js).
        $fields['default_exempt_code'] = (string) $this->exempt_code;

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
        unset($fields['buyer_address_district'], $fields['buyer_id_scheme'], $fields['buyer_id']);
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
