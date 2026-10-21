<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone an organization buys from, named on its purchase invoices.
 *
 * The organization's own list, apart from the platform's suppliers
 * (Billing): tenant-scoped, and removed rather than deleted, so the
 * purchases that name it keep their supplier.
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string|null $name_ar
 * @property string|null $vat_number
 * @property string|null $country_code
 * @property string|null $city
 * @property string|null $subdivision
 * @property string|null $email
 * @property string|null $phone
 */
class Supplier extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $table = 'book_suppliers';

    protected $fillable = [
        'org_id',
        'name',
        'name_ar',
        'vat_number',
        'country_code',
        'city',
        // The emirate, for a supplier in the UAE.
        'subdivision',
        'email',
        'phone',
    ];

    /**
     * Who made, changed and removed it, and how it arrived: by hand or from
     * a purchase CSV.
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
        ];
    }
}
