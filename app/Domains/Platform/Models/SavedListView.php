<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's named filters on one list. Read through SavedListViews only.
 *
 * Not tenant-scoped: an operator's views have no organization, and
 * SavedListViews names the person and the scope in every query.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $org_id
 * @property string $scope
 * @property string $list
 * @property string $name
 * @property string $query
 */
class SavedListView extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'org_id',
        'scope',
        'list',
        'name',
        'query',
    ];
}
