<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Services\InvoiceNumbering;
use App\Domains\Invoice\Services\NumberingSettings;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\Tills;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The organization's invoice numbering, from the portal.
 *
 * Whether each till numbers from a series of its own (the default) or every
 * branch from the organization's one; the organization's sequences; and a
 * till's own, chosen with ?branch=. Its administrators only, for the
 * organization the session works in.
 */
class PortalNumberingController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly NumberingSettings $settings,
        private readonly Tills $tills,
    ) {}

    public function edit(Request $request): View
    {
        $organizationId = $this->organization($request);
        // Each till's own series: a branch's first till has the branch's id.
        $branches = $this->tills->series($organizationId);
        $branchId = in_array($request->query('branch'), array_column($branches, 'id'), true) ? (string) $request->query('branch') : null;

        return view('portal.numbering', [
            'sequences' => $this->settings->sequences($organizationId, $branchId),
            'shared' => InvoiceNumbering::shared($organizationId),
            'branches' => $branches,
            'branchId' => $branchId,
        ]);
    }

    public function update(Request $request, string $documentType): RedirectResponse
    {
        $organizationId = $this->organization($request);

        abort_unless(in_array($documentType, NumberingSettings::TYPES, true), 404);

        $branchId = $request->filled('branch_id') ? (string) $request->input('branch_id') : null;
        abort_unless($branchId === null || in_array($branchId, array_column($this->tills->series($organizationId), 'id'), true), 404);

        $validated = $request->validateWithBag(
            $documentType,
            $this->settings->rules($organizationId, $documentType, $request->input('reset'), $branchId),
            NumberingSettings::messages(),
        );

        $this->settings->save($organizationId, $documentType, $validated, $branchId);

        return redirect()->route('portal.numbering.edit', array_filter(['branch' => $branchId]))->with('success', match ($documentType) {
            'credit_note' => __('Credit note numbering saved.'),
            'debit_note' => __('Debit note numbering saved.'),
            'prepayment' => __('Prepayment numbering saved.'),
            default => __('Invoice numbering saved.'),
        });
    }

    /** One series for every branch, or each till its own. */
    public function mode(Request $request): RedirectResponse
    {
        $organizationId = $this->organization($request);
        $validated = $request->validate(['shared_numbering' => ['required', 'boolean']]);

        $this->settings->share($organizationId, (bool) $validated['shared_numbering']);

        return redirect()->route('portal.numbering.edit')->with('success', __('Numbering saved.'));
    }

    private function organization(Request $request): string
    {
        return $this->portalOrganizationId($request, __('Only an administrator of this organization can change its numbering.'));
    }
}
