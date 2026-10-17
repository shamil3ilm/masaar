<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Services\CountryReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Which countries the platform issues in, switched from a screen rather than
 * an environment variable and a restart.
 *
 * Every country has the same switch: on or off, recorded with who and when
 * (JurisdictionAvailability), and audited like every console action
 * (AuditAdminAction). Before anyone switches one, the screen says what the
 * module reports about being ready and how many organizations it would stop.
 *
 * Switching off stops new issuing in that country - the engine refuses it,
 * the API answers 422 JURISDICTION_UNAVAILABLE, sign-up stops offering it -
 * and leaves what is already issued to finish filing or sending.
 */
class AdminCountryController extends Controller
{
    public function __construct(
        private readonly CountryReport $report,
        private readonly JurisdictionRegistry $registry,
        private readonly JurisdictionAvailability $availability,
    ) {}

    public function index(): View
    {
        return view('admin.countries', [
            'countries' => $this->report->rows(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'country' => ['required', 'string', Rule::in($this->registry->registered())],
            'enabled' => ['required', 'boolean'],
        ]);

        $code = (string) $validated['country'];
        $enabled = (bool) $validated['enabled'];
        $jurisdiction = $this->registry->get($code);
        $before = $jurisdiction->enabled();

        // The email, not the id: read by someone asking who switched a
        // country off, as the settings and kill switches record it.
        $this->availability->set($code, $enabled, Auth::user()?->email);

        $request->attributes->set(AuditAdminAction::TARGET, ['Jurisdiction', $code]);
        $request->attributes->set(AuditAdminAction::CHANGES, [['enabled' => $before], ['enabled' => $enabled]]);

        return redirect()->route('admin.countries')->with('success', $enabled
            ? $jurisdiction->name().' is switched on. Its organizations can issue again.'
            : $jurisdiction->name().' is switched off. Nothing new is issued there; what was already issued keeps being filed.');
    }
}
