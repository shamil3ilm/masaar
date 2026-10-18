<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Platform\Customization\CustomizationForm;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The organization's own values for every customization it can set, each
 * against what it would inherit from the platform. Its administrators only.
 */
class PortalSettingsController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly Customizations $customizations,
        private readonly CustomizationForm $form,
    ) {}

    public function edit(Request $request): View
    {
        $organizationId = $this->organizationId($request);

        return view('portal.settings', [
            'rows' => $this->customizations->describe(Level::Organization, Scope::organization($organizationId)),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $organizationId = $this->organizationId($request);

        // Requiring a second factor of everyone needs the administrator to
        // have one, or their next page would send them away mid-change.
        if ($key === 'security.require_two_factor' && ! $request->boolean('reset') && $request->boolean('value') && ! $request->user()->hasTwoFactor()) {
            return redirect()->route('portal.settings.edit')->with('error', __('Turn on two-factor for your own account first, on My account.'));
        }

        try {
            $message = $this->form->apply($request, $key, Level::Organization, Scope::organization($organizationId), (string) $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->route('portal.settings.edit')->with('error', $e->getMessage());
        }

        return redirect()->route('portal.settings.edit')->with('success', $message);
    }

    private function organizationId(Request $request): string
    {
        return $this->portalOrganizationId($request, __('Only an administrator of this organization can change its settings.'));
    }
}
