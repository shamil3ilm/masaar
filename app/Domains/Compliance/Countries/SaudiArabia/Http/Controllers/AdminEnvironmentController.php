<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitch;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A platform admin reads and sets the ZATCA environment a Saudi
 * organization works in (FatooraEnvironment). EnvironmentSwitch archives
 * its credentials and audits the change with the admin's id; admin.audit
 * records the request too.
 */
class AdminEnvironmentController extends Controller
{
    public function __construct(
        private readonly EnvironmentSwitch $switch,
    ) {}

    /**
     * GET /admin/organizations/{id}/zatca-environment
     */
    public function show(string $id): View
    {
        $organization = $this->organization($id);

        return view('countries.saudi-arabia.admin.environment', [
            'organization' => $organization,
            'environment' => FatooraEnvironment::of($organization),
            'chosen' => FatooraEnvironment::chosen($organization),
            'default' => FatooraEnvironment::default(),
            'environments' => FatooraEnvironment::ALL,
        ]);
    }

    /**
     * POST /admin/organizations/{id}/zatca-environment
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $organization = $this->organization($id);

        $validated = $request->validate([
            'environment' => ['required', 'string', Rule::in(FatooraEnvironment::ALL)],
        ]);

        try {
            $this->switch->set($organization, $validated['environment'], (string) $request->user()?->id);
        } catch (DomainException $e) {
            return redirect()->route('admin.organizations.zatca-environment', $organization->id)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.organizations.zatca-environment', $organization->id)->with('success', 'ZATCA environment saved.');
    }

    private function organization(string $id): Organization
    {
        return $this->switch->organization($id) ?? abort(404);
    }
}
