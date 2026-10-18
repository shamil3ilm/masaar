<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Customization\CustomizationForm;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The platform's default for one customization: what every organization and
 * person inherits until they set their own, and - for one that can be
 * locked - what they cannot set at all.
 */
class AdminCustomizationController extends Controller
{
    public function __construct(
        private readonly CustomizationForm $form,
    ) {}

    public function update(Request $request, string $key): RedirectResponse
    {
        // The key is in the recorded parameters; the entity id is a UUID column.
        $request->attributes->set(AuditAdminAction::TARGET, ['Customization', null]);

        try {
            $message = $this->form->apply($request, $key, Level::Platform, Scope::platform(), (string) Auth::id());
        } catch (DomainException $e) {
            return redirect()->route('admin.settings')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.settings')->with('success', $message);
    }
}
