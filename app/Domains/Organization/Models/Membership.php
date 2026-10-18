<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One person's place in one organization: their role and whether it is
 * active, and who added them and last changed it (RecordsActors).
 *
 * The pivot of User::organizations() and Organization::users(), so a
 * membership attached or changed through either is attributed. Ending one
 * sets its status to removed; the row stays.
 */
class Membership extends Pivot
{
    use RecordsActors;

    protected $table = 'organization_user';

    /**
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by'];
    }
}
