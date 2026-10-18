<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Auth\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approver's approval of a request: append-only, so created_at and no
 * updated_at. A rejection is the request's own decision (rejected_by and
 * decision_note), since one ends it.
 */
class ApprovalDecision extends Model
{
    use HasUuids;

    public const APPROVE = 'approve';

    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'user_id',
        'decision',
        'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
