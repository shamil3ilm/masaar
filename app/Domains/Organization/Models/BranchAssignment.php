<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person at one branch, and what they may do there: issue (member) or
 * read (viewer). Read and written through BranchAccess only.
 *
 * Someone with no assignment works across the whole organization, as
 * everyone did before branches could be assigned; someone with one or more
 * works at those branches alone. Taking someone off soft-deletes the row,
 * and putting them back restores it.
 *
 * @property string $id
 * @property string $org_id
 * @property string $branch_id
 * @property string $user_id
 * @property string $role
 */
class BranchAssignment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $table = 'branch_user';

    protected $fillable = [
        'org_id',
        'branch_id',
        'user_id',
        'role',
    ];
}
