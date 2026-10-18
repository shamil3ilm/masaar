<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Services\CountryReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    use HoldsForApproval;

    public function __construct(
        private readonly CountryReport $report,
        private readonly JurisdictionRegistry $registry,
        private readonly Approvals $approvals,
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

        $request->attributes->set(AuditAdminAction::TARGET, ['Jurisdiction', $code]);
        $request->attributes->set(AuditAdminAction::CHANGES, [['enabled' => $this->registry->get($code)->enabled()], ['enabled' => $enabled]]);

        return $this->holdOrRun($this->approvals, $request, 'countries.switch', ['country' => $code, 'enabled' => $enabled],
            fn (ActionResult $done): RedirectResponse => redirect()->route('admin.countries')->with('success', $done->message));
    }
}
