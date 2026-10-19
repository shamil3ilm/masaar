<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\UnitRetirement;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\Tills;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A branch's tills from the portal: adding one, and retiring one. Each is
 * its own EGS unit, connected to ZATCA with its own OTP from its
 * certificate page. Its administrators only.
 */
class PortalTillController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BranchService $branches,
        private readonly Tills $tills,
        private readonly UnitRetirement $retirement,
    ) {}

    public function store(Request $request, string $branchId): RedirectResponse
    {
        $organization = $this->organization($request);
        $branch = $this->branches->find((string) $organization->id, $branchId) ?? abort(404);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);

        $unit = $this->tills->add($branch, $data['name'] ?? null);

        return redirect()->route('portal.branches.edit', $branch->id)
            ->with('success', __('Till :number added. Connect it to ZATCA with its own one-time code.', ['number' => $unit->till_number]));
    }

    public function retire(Request $request, string $unitId): RedirectResponse
    {
        $organization = $this->organization($request);
        $unit = $this->tills->find((string) $organization->id, $unitId) ?? abort(404);

        try {
            $unit = $this->retirement->retire($organization, $unit, (string) $request->user()?->getAuthIdentifier());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $unit->status === EgsUnit::RETIRED
            ? __(':till is retired. It never issues again.', ['till' => $this->tills->label($unit)])
            : trans_choice(':till issues nothing more. One document it signed is still to be filed with ZATCA; retire it again once it is.|:till issues nothing more. :count documents it signed are still to be filed with ZATCA; retire it again once they are.', $this->retirement->waiting($unit)->count(), [
                'till' => $this->tills->label($unit),
                'count' => $this->retirement->waiting($unit)->count(),
            ]));
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can manage its tills.'));
    }
}
