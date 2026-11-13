<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Http\Requests\ApproveOrganizationRequest;
use App\Domains\Platform\Http\Requests\RejectOrganizationRequest;
use App\Domains\Platform\Http\Requests\SuspendOrganizationRequest;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Approving, or turning down, a business that signed itself up.
 */
class AdminOrganizationReviewController extends Controller
{
    use HoldsForApproval;

    public function __construct(
        private readonly Approvals $approvals,
    ) {}

    /**
     * Approve, and assign the plan it files under. Lands on the new licence.
     * Its secret is not shown: the company makes its own API keys in the
     * portal (Integrations), so no one at the platform holds one of them.
     */
    public function approve(ApproveOrganizationRequest $request, string $id): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $data = $request->validated();

        try {
            return $this->holdOrRun($this->approvals, $request, 'organizations.approve', [
                'organization_id' => $id,
                'tier' => $data['tier'],
                'expires_at' => $data['expires_at'] ?? null,
            ], fn (ActionResult $approved): RedirectResponse => redirect()
                ->route('admin.licences.show', $approved->data['licence_id'])
                ->with('success', $approved->message.' '.__('The company makes its own API keys in the portal, under Integrations.')));
        } catch (DomainException $e) {
            return redirect()->route('admin.organization.detail', $id)->with('error', $e->getMessage());
        }
    }

    /**
     * Suspend an active organization, with a reason. It issues nothing new;
     * its people keep reading and exporting, and what it issued keeps being
     * filed (OrganizationStatus).
     */
    public function suspend(SuspendOrganizationRequest $request, string $id): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $reason = (string) $request->validated('reason');

        return $this->decided($request, $id, 'organizations.suspend', ['organization_id' => $id, 'reason' => $reason], $reason);
    }

    public function reactivate(Request $request, string $id): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        return $this->decided($request, $id, 'organizations.reactivate', ['organization_id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function decided(Request $request, string $id, string $action, array $payload, ?string $reason = null): RedirectResponse
    {
        try {
            return $this->holdOrRun($this->approvals, $request, $action, $payload,
                fn (ActionResult $done): RedirectResponse => redirect()->route('admin.organization.detail', $id)->with('success', $done->message), $reason);
        } catch (DomainException $e) {
            return redirect()->route('admin.organization.detail', $id)->with('error', $e->getMessage());
        }
    }

    public function reject(RejectOrganizationRequest $request, string $id): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $reason = (string) $request->validated('reason');

        try {
            return $this->holdOrRun($this->approvals, $request, 'organizations.reject', ['organization_id' => $id, 'reason' => $reason],
                fn (ActionResult $done): RedirectResponse => redirect()->route('admin.organization.detail', $id)->with('success', $done->message),
                $reason);
        } catch (DomainException $e) {
            return redirect()->route('admin.organization.detail', $id)->with('error', $e->getMessage());
        }
    }
}
