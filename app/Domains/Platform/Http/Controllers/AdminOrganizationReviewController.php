<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Organization\Services\OrganizationReview;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Approving, or turning down, a business that signed itself up.
 */
class AdminOrganizationReviewController extends Controller
{
    public function __construct(
        private readonly OrganizationReview $review,
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
            $approved = $this->review->approve($id, (string) Auth::id(), $data['tier'], $data['expires_at'] ?? null);
        } catch (DomainException $e) {
            return redirect()->route('admin.organization.detail', $id)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.licences.show', $approved['licence_id'])
            ->with('success', 'Approved. Its admins have been told by email, and this is its plan.')
            ->with('api_secret', $approved['api_secret']);
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->review->reject($id, (string) Auth::id(), $data['reason']);
        } catch (DomainException $e) {
            return redirect()->route('admin.organization.detail', $id)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.organization.detail', $id)
            ->with('success', 'Not approved. Its admins have been told why by email.');
    }
}
