<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform admin's hand on a customer's account: frozen, or released
 * from the overdue-payment freeze until a date. The latest decides.
 */
class AccountHold extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    public const FREEZE = 'freeze';

    public const RELEASE = 'release';

    protected $fillable = ['org_id', 'kind', 'reason', 'released_until'];

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return ['released_until' => 'date'];
    }
}
