<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * An admin's change to the shipped approval policy for one action. Read
 * through Approvals, which falls back to the shipped default without one.
 *
 * Who made and last changed it is filled from the signed-in admin and is
 * not mass-assignable.
 *
 * @property string $action
 * @property bool $enabled
 * @property int $approvals_required
 * @property list<string> $approver_roles
 * @property int $expires_after_hours
 */
class ApprovalPolicy extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'action',
        'enabled',
        'approvals_required',
        'approver_roles',
        'expires_after_hours',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'approvals_required' => 'integer',
            'approver_roles' => 'array',
            'expires_after_hours' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $policy) => $policy->created_by ??= Auth::id());
        // A service that knows who asked says so; otherwise the signed-in admin.
        static::saving(function (self $policy): void {
            if (! $policy->isDirty('updated_by')) {
                $policy->updated_by = Auth::id() ?? $policy->updated_by;
            }
        });
    }
}
