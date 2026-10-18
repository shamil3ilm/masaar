<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\OrganizationReview;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Deciding on an organization that signed itself up: approving it with its
 * plan, or turning it down with a reason.
 */
final class OrganizationActions implements ApprovableActions
{
    public function __construct(
        private readonly OrganizationReview $review,
    ) {}

    public function keys(): array
    {
        return ['organizations.approve', 'organizations.reject'];
    }

    public function label(string $action): string
    {
        return match ($action) {
            'organizations.approve' => __('Approve an organization and assign its plan'),
            default => __('Turn down an organization'),
        };
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::OrganizationsReview;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $organization = $this->organization((string) $payload['organization_id']);
        $before = ['status' => $organization->status];

        return $action === 'organizations.approve'
            ? new Preview(
                __('Approve :organization on the :tier plan', ['organization' => $organization->name, 'tier' => $payload['tier']]),
                'Organization', (string) $organization->id, $before,
                ['status' => Organization::STATUS_ACTIVE, 'tier' => $payload['tier'], 'expires_at' => $payload['expires_at'] ?? null],
            )
            : new Preview(
                __('Turn down :organization', ['organization' => $organization->name]),
                'Organization', (string) $organization->id, $before,
                ['status' => Organization::STATUS_REJECTED, 'reason' => $payload['reason']],
            );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        if ($action === 'organizations.approve') {
            $approved = $this->review->approve((string) $payload['organization_id'], (string) $requester->id, (string) $payload['tier'], $payload['expires_at'] ?? null);

            return new ActionResult(__('Approved. Its admins have been told by email, and this is its plan.'), [
                'licence_id' => $approved['licence_id'],
                'api_secret' => $approved['api_secret'],
            ]);
        }

        $this->review->reject((string) $payload['organization_id'], (string) $requester->id, (string) $payload['reason']);

        return new ActionResult(__('Not approved. Its admins have been told why by email.'));
    }

    private function organization(string $id): Organization
    {
        return Organization::query()->find($id) ?? throw new DomainException(__('There is no such organization.'));
    }
}
