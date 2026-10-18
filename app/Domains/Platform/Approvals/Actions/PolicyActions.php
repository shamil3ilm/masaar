<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\PlatformOwnership;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\ApprovalCatalogue;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Changing an approval policy. Itself held back whenever any policy is on
 * (ApprovalPolicies::POLICIES), so turning approvals off needs approval too.
 *
 * Bypass is turned off only while another admin could approve: with nobody
 * else, every held action would wait for good and lock the owner out. A
 * sole owner locked out all the same recovers from the server
 * (masaar:owner-recover, docs/architecture/APPROVALS.md).
 */
final class PolicyActions implements ApprovableActions
{
    public function __construct(
        private readonly ApprovalPolicies $policies,
        private readonly ApprovalCatalogue $catalogue,
    ) {}

    public function keys(): array
    {
        return [ApprovalPolicies::POLICIES];
    }

    public function label(string $action): string
    {
        return __('Change an approval policy');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::PlatformConfigure;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $target = (string) $payload['action'];

        if ($target === ApprovalPolicies::BYPASS) {
            if ($requester->adminRole() !== PlatformOwnership::OWNER) {
                throw new DomainException(__('Only the platform owner can change whether approvals may be bypassed.'));
            }

            $this->assertNotLockingOut($payload, $requester);

            return new Preview(__('Approval policy for: :action', ['action' => $this->catalogue->label($target)]), 'ApprovalPolicy', null,
                ['enabled' => $this->policies->allowsBypass()], ['enabled' => (bool) ($payload['values']['enabled'] ?? false)]);
        }

        return new Preview(
            __('Approval policy for: :action', ['action' => $this->catalogue->label($target)]),
            'ApprovalPolicy', null,
            $this->policies->get($target)->toArray(),
            $this->policies->clean($target, (array) $payload['values']),
        );
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $target = (string) $payload['action'];

        if ($target === ApprovalPolicies::BYPASS) {
            $this->assertNotLockingOut($payload, $requester);
        }

        $this->policies->save($target, $this->policies->clean($target, (array) $payload['values']), (string) $requester->id);

        return new ActionResult(__('The approval policy for :action is saved.', ['action' => $this->catalogue->label($target)]));
    }

    /**
     * Refuse turning bypass off while nobody but the requester could decide
     * a held action: an active platform admin whose role decides approvals.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException
     */
    private function assertNotLockingOut(array $payload, User $requester): void
    {
        if ((bool) ($payload['values']['enabled'] ?? false)) {
            return;
        }

        if (! $this->policies->anotherApproverExists($requester)) {
            throw new DomainException(__('Bypass stays on: no other admin can approve a request yet, so with it off every action an approval policy holds would wait for good, yours included. Add an admin whose role decides approvals first.'));
        }
    }
}
