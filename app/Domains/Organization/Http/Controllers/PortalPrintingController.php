<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The paper invoices print on: each person's own, and - for the
 * organization's administrators - the organization's and each branch's.
 */
class PortalPrintingController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly PrintPreferences $preferences,
    ) {}

    public function edit(Request $request): View
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $organizationId = (string) $organization->id;

        return view('portal.printing', [
            'papers' => PaperSize::cases(),
            'mine' => $this->preferences->userPaper($organizationId, (string) $request->user()?->id),
            'admin' => $request->user()?->roleIn($organizationId) === 'admin',
            'organizationPaper' => $this->preferences->organizationPaper($organization),
            'branches' => $this->preferences->branches($organizationId),
        ]);
    }

    /** The person's own paper; empty for the organization's. */
    public function updateMine(Request $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        $paper = $request->validate(['paper' => ['nullable', Rule::enum(PaperSize::class)]])['paper'] ?? null;

        $this->preferences->setUserPaper($organizationId, (string) $request->user()?->id, PaperSize::tryFrom((string) $paper));

        return redirect()->route('portal.printing.edit')->with('success', __('Your paper is saved.'));
    }

    /** The organization's paper and each branch's. */
    public function update(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can change its printing.'));
        $organizationId = (string) $organization->id;
        $validated = $request->validate([
            'paper' => ['required', Rule::enum(PaperSize::class)],
            'branches' => ['array'],
            'branches.*' => ['nullable', Rule::enum(PaperSize::class)],
            'printers' => ['array'],
            'printers.*.dpi' => ['nullable', 'integer', 'between:100,600'],
            'printers.*.dots' => ['nullable', 'integer', 'between:64,1280', 'multiple_of:8'],
        ]);

        $actorId = (string) $request->user()?->id;
        $this->preferences->setOrganizationPaper($organization, PaperSize::from($validated['paper']), $actorId);

        foreach ($this->preferences->branches($organizationId) as $branch) {
            $printer = $validated['printers'][$branch['id']] ?? [];
            $this->preferences->setBranchPaper($organizationId, $branch['id'], PaperSize::tryFrom((string) ($validated['branches'][$branch['id']] ?? '')), $actorId);
            $this->preferences->setReceiptPrinter(
                $organizationId,
                $branch['id'],
                isset($printer['dpi']) ? (int) $printer['dpi'] : null,
                isset($printer['dots']) ? (int) $printer['dots'] : null,
                $actorId,
            );
        }

        return redirect()->route('portal.printing.edit')->with('success', __('Printing settings saved.'));
    }
}
