<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

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
        // An insurer: the buyer of the insurer's share of a split sale.
        'is_insurer',
        'archived_at',
    ];

    /** Computed from deleted_at for the unique indexes (0960); never written. */
    protected $hidden = ['not_deleted'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'is_insurer' => 'boolean'];
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
     * What the invoice form's buyer fields are filled with; a country's own
     * form adds its fields to these (Presentation::buyerFields).
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
}
