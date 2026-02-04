<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalWorkflowStep extends Model
{
    public const APPROVER_TYPE_USER = 'user';
    public const APPROVER_TYPE_ROLE = 'role';
    public const APPROVER_TYPE_DEPARTMENT_HEAD = 'department_head';
    public const APPROVER_TYPE_REPORTING_MANAGER = 'reporting_manager';
    public const APPROVER_TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'approval_workflow_id',
        'name',
        'sequence',
        'approver_type',
        'approver_id',
        'approver_custom',
        'requires_all',
        'min_approvers',
        'timeout_hours',
        'can_skip',
        'can_delegate',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'requires_all' => 'boolean',
        'min_approvers' => 'integer',
        'timeout_hours' => 'integer',
        'can_skip' => 'boolean',
        'can_delegate' => 'boolean',
    ];

    // Relationships

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'workflow_step_id');
    }

    // Scopes

    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence');
    }

    // Helper Methods

    /**
     * Get approvers for this step based on context.
     */
    public function getApprovers(array $context = []): array
    {
        return match ($this->approver_type) {
            self::APPROVER_TYPE_USER => $this->getUserApprovers(),
            self::APPROVER_TYPE_ROLE => $this->getRoleApprovers(),
            self::APPROVER_TYPE_DEPARTMENT_HEAD => $this->getDepartmentHeadApprover($context),
            self::APPROVER_TYPE_REPORTING_MANAGER => $this->getReportingManagerApprover($context),
            self::APPROVER_TYPE_CUSTOM => $this->getCustomApprovers($context),
            default => [],
        };
    }

    protected function getUserApprovers(): array
    {
        if (!$this->approver_id) {
            return [];
        }

        $user = User::find($this->approver_id);

        return $user ? [$user->id] : [];
    }

    protected function getRoleApprovers(): array
    {
        if (!$this->approver_id) {
            return [];
        }

        return User::whereHas('roles', function ($query) {
            $query->where('roles.id', $this->approver_id);
        })
            ->where('organization_id', $this->workflow->organization_id)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();
    }

    protected function getDepartmentHeadApprover(array $context): array
    {
        $submitterId = $context['submitted_by'] ?? null;

        if (!$submitterId) {
            return [];
        }

        // Get the submitter's department head
        $employee = \App\Models\HR\Employee::where('user_id', $submitterId)->first();

        if (!$employee || !$employee->department) {
            return [];
        }

        $headId = $employee->department->head_id;

        return $headId ? [$headId] : [];
    }

    protected function getReportingManagerApprover(array $context): array
    {
        $submitterId = $context['submitted_by'] ?? null;

        if (!$submitterId) {
            return [];
        }

        $employee = \App\Models\HR\Employee::where('user_id', $submitterId)->first();

        if (!$employee || !$employee->reporting_manager_id) {
            return [];
        }

        return [$employee->reporting_manager_id];
    }

    protected function getCustomApprovers(array $context): array
    {
        // Implement custom approver logic based on approver_custom field
        // This could call a custom service or use a strategy pattern
        return [];
    }

    /**
     * Check if step requires all approvers.
     */
    public function requiresAllApprovers(): bool
    {
        return $this->requires_all;
    }

    /**
     * Get timeout datetime.
     */
    public function getTimeoutAt(): ?\DateTime
    {
        if (!$this->timeout_hours) {
            return null;
        }

        return now()->addHours($this->timeout_hours);
    }
}
