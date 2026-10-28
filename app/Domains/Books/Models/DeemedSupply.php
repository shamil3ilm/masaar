<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Books\Enums\DeemedSupplyKind;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A deemed supply recorded in Books, and its outcome: taxed on a kept
 * invoice, relieved under a threshold, or not a deemed supply (DeemedSupplies).
 * Never changed.
 *
 * @property string $id
 * @property string $org_id
 * @property DeemedSupplyKind $kind
 * @property string $description
 * @property string|null $description_ar
 * @property string|null $recipient
 * @property Carbon $supplied_on
 * @property string $value
 * @property string $input_tax_percent
 * @property string $outcome
 * @property string $reason
 * @property string $taxable_value
 * @property string $tax_before_cap
 * @property string $tax_amount
 * @property string|null $invoice_id
 */
class DeemedSupply extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const TAXED = 'taxed';

    public const RELIEVED = 'relieved';

    public const NOT_DEEMED = 'not_deemed';

    public const UPDATED_AT = null;

    protected $fillable = [
        'org_id', 'branch_id', 'kind', 'description', 'description_ar', 'recipient', 'supplied_on', 'value', 'input_tax_percent',
        'outcome', 'reason', 'taxable_value', 'tax_before_cap', 'tax_amount', 'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DeemedSupplyKind::class,
            'supplied_on' => 'date',
            'value' => 'decimal:2',
            'input_tax_percent' => 'decimal:2',
            'taxable_value' => 'decimal:2',
            'tax_before_cap' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via', 'license_id' => 'license_id'];
    }
}
