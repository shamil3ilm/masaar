<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person told when a board item moves on. Unfollowing removes the row;
 * following again brings it back.
 *
 * @property string $board_item_id
 * @property string $org_id
 * @property string $user_id
 */
class FeatureBoardFollower extends Model
{
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $fillable = ['board_item_id', 'org_id', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
