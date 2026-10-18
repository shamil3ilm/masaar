<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Auth\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An admin action waiting for approval, and what became of it.
 *
 * pending   waiting for approvers
 * executed  approved, and done exactly as requested
 * failed    approved, and refused when it ran - the state had moved on
 * rejected  an approver turned it down, with a reason
 * withdrawn the requester took it back
 * expired   nobody decided in time
 *
 * @property string $id
 * @property string $action
 * @property array<string, mixed> $payload
 * @property string $summary
 * @property array{before: array<string, mixed>, after: array<string, mixed>} $changes
 * @property string $reason
 * @property string $requested_by
 * @property string $status
 * @property int $approvals_required
 * @property list<string> $approver_roles
 * @property Carbon $expires_at
 */
class ApprovalRequest extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const EXECUTED = 'executed';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'action',
        'payload',
        'summary',
        'changes',
        'target_type',
        'target_id',
        'target_user_id',
        'reason',
        'requested_by',
        'status',
        'approvals_required',
        'approver_roles',
        'expires_at',
        'decided_at',
        'decided_by',
        'decision_note',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'changes' => 'array',
            'approver_roles' => 'array',
            'approvals_required' => 'integer',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'request_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => __('Waiting for approval'),
            self::EXECUTED => __('Approved and done'),
            self::FAILED => __('Approved, then refused'),
            self::REJECTED => __('Rejected'),
            self::WITHDRAWN => __('Withdrawn'),
            default => __('Expired'),
        };
    }

    /** The badge tone its status reads in. */
    public function tone(): string
    {
        return match ($this->status) {
            self::PENDING => 'warning',
            self::EXECUTED => 'success',
            self::FAILED, self::REJECTED, self::EXPIRED => 'danger',
            default => 'neutral',
        };
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}
