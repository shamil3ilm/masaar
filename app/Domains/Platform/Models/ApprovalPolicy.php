<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin's change to the shipped approval policy for one action. Read
 * through Approvals, which falls back to the shipped default without one.
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

    protected $fillable = [
        'action',
        'enabled',
        'approvals_required',
        'approver_roles',
        'expires_after_hours',
        'updated_by',
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
}
