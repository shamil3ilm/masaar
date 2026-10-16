<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

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
}
