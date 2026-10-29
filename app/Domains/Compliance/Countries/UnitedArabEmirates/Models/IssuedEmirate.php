<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The emirate a sale was made from when its invoice was issued
 * (SupplyEmirate::record), written once.
 *
 * @property string $invoice_id
 * @property string $org_id
 * @property string|null $emirate
 * @property Carbon $issued_on
 */
class IssuedEmirate extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $primaryKey = 'invoice_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['invoice_id', 'org_id', 'emirate', 'issued_on'];

    protected function casts(): array
    {
        return ['issued_on' => 'date'];
    }
}
