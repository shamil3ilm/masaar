<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\ApprovalAction;
use App\Models\Core\ApprovalDelegation;
use App\Models\Core\ApprovalRequest;
use App\Models\Core\ApprovalWorkflow;
use App\Models\Core\ApprovalWorkflowStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ApprovalWorkflowService
{
    /**
     * Submit a document for approval.
     */
    public function submit(Model $approvable, ?float $amount = null, ?string $notes = null): ApprovalRequest
    {
        // Find applicable workflow
        $workflow = $this->findApplicableWorkflow($approvable, $amount);

        if (!$workflow) {
            throw new \InvalidArgumentException('No approval workflow configured for this document type.');
        }

        return DB::transaction(function () use ($approvable, $workflow, $amount, $notes) {
            $firstStep = $workflow->getFirstStep();

            if (!$firstStep) {
                throw new \InvalidArgumentException('Approval workflow has no steps configured.');
            }

            // Create approval request
            $request = ApprovalRequest::create([
                'organization_id' => auth()->user()->organization_id,
                'approval_workflow_id' => $workflow->id,
                'approvable_type' => get_class($approvable),
                'approvable_id' => $approvable->id,
                'current_step_id' => $firstStep->id,
                'status' => ApprovalRequest::STATUS_IN_PROGRESS,
                'amount' => $amount,
                'notes' => $notes,
                'submitted_at' => now(),
                'submitted_by' => auth()->id(),
            ]);

            // Create approval actions for first step
            $this->createActionsForStep($request, $firstStep);

            // Update approvable status if it has a status field
            if (method_exists($approvable, 'markPendingApproval')) {
                $approvable->markPendingApproval();
            }

            return $request->fresh(['workflow', 'actions.assignedTo']);
        });
    }

    /**
     * Find applicable workflow for an approvable.
     */
    public function findApplicableWorkflow(Model $approvable, ?float $amount = null): ?ApprovalWorkflow
    {
        $type = get_class($approvable);

        $query = ApprovalWorkflow::active()
            ->forType($type)
            ->byPriority();

        if ($amount !== null) {
            $query->forAmount($amount);
        }

        // Check conditions
        $workflows = $query->get();

        foreach ($workflows as $workflow) {
            $context = $this->buildContext($approvable);

            if ($workflow->matchesConditions($context)) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * Build context for workflow condition checking.
     */
    protected function buildContext(Model $approvable): array
    {
        $context = $approvable->toArray();
        $context['submitted_by'] = auth()->id();
        $context['organization_id'] = auth()->user()?->organization_id;

        return $context;
    }

    /**
     * Create approval actions for a step.
     */
    protected function createActionsForStep(ApprovalRequest $request, ApprovalWorkflowStep $step): void
    {
        $context = [
            'submitted_by' => $request->submitted_by,
            'approvable' => $request->approvable,
        ];

        $approverIds = $step->getApprovers($context);

        if (empty($approverIds)) {
            // No approvers found - auto-approve step if allowed
            if ($step->can_skip) {
                $this->advanceToNextStep($request);
                return;
            }

            throw new \RuntimeException("No approvers found for step: {$step->name}");
        }

        $expiresAt = $step->getTimeoutAt();

        foreach ($approverIds as $approverId) {
            // Check for delegation
            $effectiveApprover = $this->getEffectiveApprover($approverId, $request->approvable_type);

            ApprovalAction::create([
                'approval_request_id' => $request->id,
                'workflow_step_id' => $step->id,
                'assigned_to' => $approverId,
                'delegated_to' => $effectiveApprover !== $approverId ? $effectiveApprover : null,
                'delegated_at' => $effectiveApprover !== $approverId ? now() : null,
                'status' => ApprovalAction::STATUS_PENDING,
                'expires_at' => $expiresAt,
            ]);
        }
    }

    /**
     * Get effective approver considering delegations.
     */
    protected function getEffectiveApprover(int $userId, ?string $approvableType = null): int
    {
        $delegation = ApprovalDelegation::where('user_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->when($approvableType, function ($q) use ($approvableType) {
                $q->where(function ($query) use ($approvableType) {
                    $query->whereNull('approvable_type')
                        ->orWhere('approvable_type', $approvableType);
                });
            })
            ->first();

        return $delegation ? $delegation->delegate_to : $userId;
    }

    /**
     * Approve an action.
     */
    public function approve(ApprovalAction $action, ?string $comments = null): ApprovalRequest
    {
        if (!$action->isPending()) {
            throw new \InvalidArgumentException('Action has already been processed.');
        }

        if (!$action->canActBy(auth()->id())) {
            throw new \InvalidArgumentException('You are not authorized to approve this action.');
        }

        return DB::transaction(function () use ($action, $comments) {
            $action->approve($comments);

            $request = $action->request;
            $step = $action->step;

            // Check if step is complete
            if ($this->isStepComplete($request, $step)) {
                $this->advanceToNextStep($request);
            }

            return $request->fresh(['workflow', 'actions']);
        });
    }

    /**
     * Reject an action.
     */
    public function reject(ApprovalAction $action, ?string $comments = null): ApprovalRequest
    {
        if (!$action->isPending()) {
            throw new \InvalidArgumentException('Action has already been processed.');
        }

        if (!$action->canActBy(auth()->id())) {
            throw new \InvalidArgumentException('You are not authorized to reject this action.');
        }

        return DB::transaction(function () use ($action, $comments) {
            $action->reject($comments);

            $request = $action->request;

            // Rejection at any step rejects the entire request
            $request->update([
                'status' => ApprovalRequest::STATUS_REJECTED,
                'completed_at' => now(),
            ]);

            // Cancel all pending actions
            $request->actions()
                ->where('status', ApprovalAction::STATUS_PENDING)
                ->update(['status' => ApprovalAction::STATUS_SKIPPED]);

            // Update approvable if it has a rejection handler
            if (method_exists($request->approvable, 'markRejected')) {
                $request->approvable->markRejected($comments);
            }

            return $request->fresh();
        });
    }

    /**
     * Check if current step is complete.
     */
    protected function isStepComplete(ApprovalRequest $request, ApprovalWorkflowStep $step): bool
    {
        $actions = $request->actions()
            ->where('workflow_step_id', $step->id)
            ->get();

        $approvedCount = $actions->where('status', ApprovalAction::STATUS_APPROVED)->count();
        $pendingCount = $actions->where('status', ApprovalAction::STATUS_PENDING)->count();

        if ($step->requiresAllApprovers()) {
            // All must approve
            return $pendingCount === 0 && $approvedCount === $actions->count();
        }

        // Check minimum approvers
        return $approvedCount >= $step->min_approvers;
    }

    /**
     * Advance to next step or complete the request.
     */
    protected function advanceToNextStep(ApprovalRequest $request): void
    {
        $currentStep = $request->currentStep;
        $nextStep = $request->workflow->getNextStep($currentStep);

        if ($nextStep) {
            // Move to next step
            $request->update(['current_step_id' => $nextStep->id]);
            $this->createActionsForStep($request, $nextStep);
        } else {
            // No more steps - approval complete
            $request->update([
                'status' => ApprovalRequest::STATUS_APPROVED,
                'completed_at' => now(),
            ]);

            // Update approvable if it has an approval handler
            if (method_exists($request->approvable, 'markApproved')) {
                $request->approvable->markApproved();
            }
        }
    }

    /**
     * Cancel an approval request.
     */
    public function cancel(ApprovalRequest $request, ?string $reason = null): ApprovalRequest
    {
        if (!$request->canBeCancelled()) {
            throw new \InvalidArgumentException('This approval request cannot be cancelled.');
        }

        return DB::transaction(function () use ($request, $reason) {
            $request->update([
                'status' => ApprovalRequest::STATUS_CANCELLED,
                'completed_at' => now(),
                'notes' => $reason,
            ]);

            // Cancel all pending actions
            $request->actions()
                ->where('status', ApprovalAction::STATUS_PENDING)
                ->update(['status' => ApprovalAction::STATUS_SKIPPED]);

            return $request->fresh();
        });
    }

    /**
     * Delegate an action to another user.
     */
    public function delegate(ApprovalAction $action, int $delegateTo): ApprovalAction
    {
        if (!$action->isPending()) {
            throw new \InvalidArgumentException('Only pending actions can be delegated.');
        }

        if (!$action->step->can_delegate) {
            throw new \InvalidArgumentException('This step does not allow delegation.');
        }

        $action->delegate($delegateTo);

        return $action->fresh(['delegatedTo']);
    }

    /**
     * Get pending approvals for a user.
     */
    public function getPendingForUser(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return ApprovalRequest::awaitingAction($userId)
            ->with(['workflow', 'approvable', 'currentStep'])
            ->orderBy('submitted_at', 'desc')
            ->get();
    }

    /**
     * Process expired actions.
     */
    public function processExpiredActions(): int
    {
        $expiredActions = ApprovalAction::expired()->get();
        $processed = 0;

        foreach ($expiredActions as $action) {
            DB::transaction(function () use ($action) {
                $action->markExpired();

                $request = $action->request;
                $step = $action->step;

                // Check if step can auto-escalate or should fail
                $pendingCount = $request->actions()
                    ->where('workflow_step_id', $step->id)
                    ->where('status', ApprovalAction::STATUS_PENDING)
                    ->count();

                if ($pendingCount === 0 && !$this->isStepComplete($request, $step)) {
                    // All actions expired without meeting minimum - expire request
                    $request->update([
                        'status' => ApprovalRequest::STATUS_EXPIRED,
                        'completed_at' => now(),
                    ]);
                }
            });

            $processed++;
        }

        return $processed;
    }

    /**
     * Get approval history for an approvable.
     */
    public function getHistory(Model $approvable): array
    {
        $requests = ApprovalRequest::forApprovable(get_class($approvable), $approvable->id)
            ->with(['workflow', 'actions.assignedTo', 'actions.actionBy', 'submittedBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $requests->map(fn($request) => [
            'id' => $request->id,
            'workflow' => $request->workflow->name,
            'status' => $request->status,
            'submitted_by' => $request->submittedBy?->name,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(),
            'actions' => $request->actions->map(fn($action) => [
                'step' => $action->step->name,
                'assigned_to' => $action->assignedTo?->name,
                'status' => $action->status,
                'action_by' => $action->actionBy?->name,
                'action_at' => $action->action_at?->toIso8601String(),
                'comments' => $action->comments,
            ]),
        ])->toArray();
    }
}
