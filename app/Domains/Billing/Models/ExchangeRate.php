<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One currency's rate to the tax currency on one day, as an admin entered
 * it in the daily rate table.
 */
class ExchangeRate extends Model
{
    use HasUuids;
    use RecordsActors;

    protected $fillable = ['currency', 'rate_date', 'rate'];

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by'];
    }

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:6',
        ];
    }
}
