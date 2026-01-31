<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Organization Lifecycle Service.
 *
 * Manages organization states for legal entity changes, mergers, and holds.
 *
 * States:
 * - active: Normal operations allowed
 * - suspended: Temporarily halted (billing, compliance issues)
 * - legally_replaced: Company merged or VAT changed (new entity exists)
 * - archived: No longer active, read-only
 * - legal_hold: Government/legal preservation order
 */
class OrganizationLifecycleService
{
    /**
     * Organization states.
     */
    public const STATE_ACTIVE = 'active';
    public const STATE_SUSPENDED = 'suspended';
    public const STATE_LEGALLY_REPLACED = 'legally_replaced';
    public const STATE_ARCHIVED = 'archived';
    public const STATE_LEGAL_HOLD = 'legal_hold';

    /**
     * Valid state transitions.
     */
    private const VALID_TRANSITIONS = [
        self::STATE_ACTIVE => [self::STATE_SUSPENDED, self::STATE_LEGALLY_REPLACED, self::STATE_LEGAL_HOLD],
        self::STATE_SUSPENDED => [self::STATE_ACTIVE, self::STATE_LEGALLY_REPLACED, self::STATE_LEGAL_HOLD],
        self::STATE_LEGALLY_REPLACED => [self::STATE_ARCHIVED, self::STATE_LEGAL_HOLD],
        self::STATE_ARCHIVED => [self::STATE_LEGAL_HOLD],
        self::STATE_LEGAL_HOLD => [], // Can only be released, not transitioned
    ];

    /**
     * Check if organization can issue invoices.
     */
    public function canIssueInvoices(Organization $organization): bool
    {
        return $organization->status === self::STATE_ACTIVE;
    }

    /**
     * Check if organization can submit to ZATCA.
     */
    public function canSubmitToZatca(Organization $organization): bool
    {
        return $organization->status === self::STATE_ACTIVE;
    }

    /**
     * Check if organization is under legal hold.
     */
    public function isUnderLegalHold(Organization $organization): bool
    {
        return $organization->status === self::STATE_LEGAL_HOLD;
    }

    /**
     * Transition organization to a new state.
     *
     * @throws \InvalidArgumentException if transition is not valid
     */
    public function transition(
        Organization $organization,
        string $newState,
        string $reason,
        string $transitionedBy
    ): array {
        $currentState = $organization->status;

        // Validate transition
        if (!$this->isValidTransition($currentState, $newState)) {
            throw new \InvalidArgumentException(
                "Invalid transition from {$currentState} to {$newState}"
            );
        }

        // Perform pre-transition actions
        $this->preTransitionActions($organization, $currentState, $newState);

        // Update state
        $organization->update(['status' => $newState]);

        // Log transition
        $this->logTransition($organization, $currentState, $newState, $reason, $transitionedBy);

        // Perform post-transition actions
        $this->postTransitionActions($organization, $currentState, $newState);

        return [
            'success' => true,
            'organization_id' => $organization->id,
            'previous_state' => $currentState,
            'new_state' => $newState,
            'reason' => $reason,
            'transitioned_by' => $transitionedBy,
            'transitioned_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Suspend an organization.
     */
    public function suspend(Organization $organization, string $reason, string $suspendedBy): array
    {
        return $this->transition($organization, self::STATE_SUSPENDED, $reason, $suspendedBy);
    }

    /**
     * Reactivate a suspended organization.
     */
    public function reactivate(Organization $organization, string $reason, string $reactivatedBy): array
    {
        return $this->transition($organization, self::STATE_ACTIVE, $reason, $reactivatedBy);
    }

    /**
     * Mark organization as legally replaced (merger, VAT change).
     *
     * @param Organization $organization The old organization
     * @param string|null $replacedById The new organization ID (if created)
     */
    public function markLegallyReplaced(
        Organization $organization,
        string $reason,
        string $markedBy,
        ?string $replacedById = null
    ): array {
        $result = $this->transition($organization, self::STATE_LEGALLY_REPLACED, $reason, $markedBy);

        // Store replacement reference
        if ($replacedById) {
            DB::table('organizations')
                ->where('id', $organization->id)
                ->update([
                    'compliance_profile' => json_encode(array_merge(
                        json_decode($organization->compliance_profile ?? '{}', true),
                        ['replaced_by' => $replacedById, 'replaced_at' => now()->toIso8601String()]
                    )),
                ]);

            $result['replaced_by'] = $replacedById;
        }

        return $result;
    }

    /**
     * Archive an organization.
     */
    public function archive(Organization $organization, string $reason, string $archivedBy): array
    {
        if ($organization->status !== self::STATE_LEGALLY_REPLACED) {
            throw new \InvalidArgumentException(
                'Only legally replaced organizations can be archived'
            );
        }

        return $this->transition($organization, self::STATE_ARCHIVED, $reason, $archivedBy);
    }

    /**
     * Place organization under legal hold.
     */
    public function placeLegalHold(
        Organization $organization,
        string $holdReference,
        string $requestedBy,
        string $reason,
        ?\DateTimeInterface $expiresAt = null
    ): array {
        $previousState = $organization->status;

        // Store legal hold metadata
        $holdMetadata = [
            'hold_reference' => $holdReference,
            'requested_by' => $requestedBy,
            'reason' => $reason,
            'previous_state' => $previousState,
            'placed_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
        ];

        DB::table('organizations')
            ->where('id', $organization->id)
            ->update([
                'status' => self::STATE_LEGAL_HOLD,
                'compliance_profile' => json_encode(array_merge(
                    json_decode($organization->compliance_profile ?? '{}', true),
                    ['legal_hold' => $holdMetadata]
                )),
            ]);

        Log::critical('LEGAL HOLD placed on organization', [
            'organization_id' => $organization->id,
            'hold_reference' => $holdReference,
            'requested_by' => $requestedBy,
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'organization_id' => $organization->id,
            'hold_reference' => $holdReference,
            'previous_state' => $previousState,
            'new_state' => self::STATE_LEGAL_HOLD,
            'placed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Release legal hold on organization.
     */
    public function releaseLegalHold(
        Organization $organization,
        string $releaseReference,
        string $releasedBy,
        string $reason
    ): array {
        if ($organization->status !== self::STATE_LEGAL_HOLD) {
            throw new \InvalidArgumentException('Organization is not under legal hold');
        }

        $complianceProfile = json_decode($organization->compliance_profile ?? '{}', true);
        $holdMetadata = $complianceProfile['legal_hold'] ?? [];
        $previousState = $holdMetadata['previous_state'] ?? self::STATE_ACTIVE;

        // Archive the hold metadata
        $complianceProfile['legal_hold_history'][] = array_merge($holdMetadata, [
            'released_at' => now()->toIso8601String(),
            'released_by' => $releasedBy,
            'release_reference' => $releaseReference,
            'release_reason' => $reason,
        ]);
        unset($complianceProfile['legal_hold']);

        DB::table('organizations')
            ->where('id', $organization->id)
            ->update([
                'status' => $previousState,
                'compliance_profile' => json_encode($complianceProfile),
            ]);

        Log::warning('Legal hold RELEASED from organization', [
            'organization_id' => $organization->id,
            'release_reference' => $releaseReference,
            'released_by' => $releasedBy,
            'restored_state' => $previousState,
        ]);

        return [
            'success' => true,
            'organization_id' => $organization->id,
            'release_reference' => $releaseReference,
            'restored_state' => $previousState,
            'released_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get lifecycle status for organization.
     */
    public function getStatus(Organization $organization): array
    {
        $complianceProfile = json_decode($organization->compliance_profile ?? '{}', true);

        return [
            'organization_id' => $organization->id,
            'current_state' => $organization->status,
            'can_issue_invoices' => $this->canIssueInvoices($organization),
            'can_submit_to_zatca' => $this->canSubmitToZatca($organization),
            'is_under_legal_hold' => $this->isUnderLegalHold($organization),
            'legal_hold_info' => $complianceProfile['legal_hold'] ?? null,
            'replaced_by' => $complianceProfile['replaced_by'] ?? null,
            'available_transitions' => self::VALID_TRANSITIONS[$organization->status] ?? [],
        ];
    }

    /**
     * Check if a state transition is valid.
     */
    private function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, self::VALID_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Pre-transition actions.
     */
    private function preTransitionActions(Organization $organization, string $from, string $to): void
    {
        // Freeze hash chain when leaving active state
        if ($from === self::STATE_ACTIVE && $to !== self::STATE_ACTIVE) {
            Log::info('Freezing hash chain for organization', [
                'organization_id' => $organization->id,
                'transition' => "{$from} → {$to}",
            ]);
        }
    }

    /**
     * Post-transition actions.
     */
    private function postTransitionActions(Organization $organization, string $from, string $to): void
    {
        // Send notifications, trigger webhooks, etc.
        Log::info('Organization state transition completed', [
            'organization_id' => $organization->id,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Log state transition.
     */
    private function logTransition(
        Organization $organization,
        string $from,
        string $to,
        string $reason,
        string $transitionedBy
    ): void {
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => Organization::class,
            'auditable_id' => $organization->id,
            'event' => 'state_transition',
            'old_values' => json_encode(['status' => $from]),
            'new_values' => json_encode(['status' => $to, 'reason' => $reason]),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => $transitionedBy,
            'tags' => json_encode(['lifecycle', $from, $to]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
