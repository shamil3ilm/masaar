<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An admin action waiting for approval, and what became of it - or, with
 * org_id, an action inside one organization its own people decide.
 *
 * Evidence of who did what, so never deleted. Its actors - who asked
 * (requested_by, created_by), who approved last, who rejected, who withdrew
 * - are set by Approvals, never mass-assigned.
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
        'status',
        'approvals_required',
        'approver_roles',
        'expires_at',
        'decided_at',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    /** Whoever ended it: the last approver, the one who rejected, or the requester withdrawing. */
    public function decidedBy(): ?User
    {
        return $this->approver ?? $this->rejecter ?? $this->withdrawer;
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

    /**
     * The platform's own requests, which its admins decide: not an
     * organization's (org_id set), which its own people decide
     * (OrganizationApprovals). The console reads only these.
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull('org_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}
