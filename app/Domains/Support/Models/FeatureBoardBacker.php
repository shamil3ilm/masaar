<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company that needs a board item too. One row per company; withdrawn,
 * it is removed, and backing again brings it back.
 *
 * Not tenant-scoped: the board counts every company's, and FeatureBoard
 * names the organization in every query that is one company's.
 *
 * @property string $board_item_id
 * @property string $org_id
 */
class FeatureBoardBacker extends Model
{
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $fillable = ['board_item_id', 'org_id'];
}
