<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A stage of a contract as agreed: its value before VAT, entered as an
 * amount or as a percentage of the contract.
 *
 * @property string $id
 * @property string $contract_id
 * @property int $position
 * @property string $name
 * @property string|null $name_ar
 * @property string|null $percent
 * @property string $amount
 */
class ContractStage extends Model
{
    use HasUuids;

    protected $fillable = ['contract_id', 'position', 'name', 'name_ar', 'percent', 'amount'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'percent' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }
}
