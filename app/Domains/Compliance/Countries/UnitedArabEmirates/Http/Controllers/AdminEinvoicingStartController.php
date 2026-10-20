<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\EinvoicingPhases;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\ProviderAppointments;
use App\Domains\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A platform admin reads and sets a UAE organization's e-invoicing start
 * date, as its administrators can on its company page - for a business
 * that told support its phase, or a correction. EinvoicingStart audits
 * the change with the admin's id; admin.audit records the request too.
 */
class AdminEinvoicingStartController extends Controller
{
    public function __construct(
        private readonly EinvoicingStart $start,
        private readonly ProviderAppointments $appointments,
    ) {}

    /**
     * GET /admin/organizations/{id}/einvoicing-start
     */
    public function show(string $id): View
    {
        $organization = $this->organization($id);

        return view('countries.united-arab-emirates.admin.einvoicing-start', [
            'organization' => $organization,
            'start' => $this->start->date($organization),
            'inScope' => $this->start->inScope($organization),
            'appointed' => $this->appointments->appointed((string) $organization->id),
            'phases' => EinvoicingPhases::MANDATORY,
            'voluntaryFrom' => EinvoicingPhases::VOLUNTARY_FROM,
        ]);
    }

    /**
     * POST /admin/organizations/{id}/einvoicing-start
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $organization = $this->organization($id);

        $validated = $request->validate([
            'einvoicing_start' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $this->start->set($organization, $validated['einvoicing_start'] ?? null, (string) $request->user()?->id);
        } catch (DomainException $e) {
            return redirect()->route('admin.organizations.einvoicing-start', $organization->id)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.organizations.einvoicing-start', $organization->id)->with('success', 'E-invoicing start date saved.');
    }

    private function organization(string $id): Organization
    {
        return $this->start->organization($id) ?? abort(404);
    }
}
