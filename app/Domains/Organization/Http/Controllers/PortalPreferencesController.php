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
 * A person's own preferences, each against what they would inherit from
 * their organization and the platform. Everyone's, a viewer's too: a
 * preference changes nothing of the organization's.
 */
class PortalPreferencesController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly Customizations $customizations,
        private readonly CustomizationForm $form,
    ) {}

    public function edit(Request $request): View
    {
        return view('portal.preferences', [
            'rows' => $this->customizations->describe(Level::User, $this->scope($request)),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        try {
            $message = $this->form->apply($request, $key, Level::User, $this->scope($request), (string) $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->route('portal.preferences.edit')->with('error', $e->getMessage());
        }

        return redirect()->route('portal.preferences.edit')->with('success', $message);
    }

    private function scope(Request $request): Scope
    {
        return Scope::member($this->portalOrganizationId($request), (string) $request->user()->id);
    }
}
