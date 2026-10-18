<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * Approve, and assign the plan it files under. Lands on the new licence,
     * which shows its API secret once - for a business that will also
     * connect its own systems to the API.
     */
    public function approve(Request $request, string $id): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $data = $request->validate([
            'tier' => ['required', Rule::in(array_column(LicenseTier::cases(), 'value'))],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        try {
            return $this->holdOrRun($this->approvals, $request, 'organizations.approve', [
                'organization_id' => $id,
                'tier' => $data['tier'],
                'expires_at' => $data['expires_at'] ?? null,
            ], fn (ActionResult $approved): RedirectResponse => redirect()
                ->route('admin.licences.show', $approved->data['licence_id'])
                ->with('success', $approved->message)
                ->with('api_secret', $approved->data['api_secret']));
        } catch (DomainException $e) {
            return redirect()->route('admin.organization.detail', $id)->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            return $this->holdOrRun($this->approvals, $request, 'organizations.reject', ['organization_id' => $id, 'reason' => $data['reason']],
                fn (ActionResult $done): RedirectResponse => redirect()->route('admin.organization.detail', $id)->with('success', $done->message),
                $data['reason']);
        } catch (DomainException $e) {
            return redirect()->route('admin.organization.detail', $id)->with('error', $e->getMessage());
        }
    }
}
