<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Exceptions\LicenceStateException;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenceHistory;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Domains\Organization\Rules\VatNumber;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Support\OperatorError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Licences, for the platform team.
 *
 * Every operation is LicenseManagementService's - create, suspend, revoke,
 * extend, upgradeTier, regenerateSecret all existed with no UI, and issuing a
 * partner a licence meant the CLI. This is a screen, not new machinery.
 *
 * Reachable only behind platform.admin, which checks users.is_platform_admin
 * and is deliberately not the per-organization admin role.
 */
class AdminLicenceController extends Controller
{
    public function __construct(
        private readonly LicenseManagementService $licences,
        private readonly LicenceHistory $history,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->lists->admin($this->history->spec('admin.licences.index'), $request, 'admin.licences.export');

        return view('admin.licences.index', [
            'licences' => $this->history->page($list),
            'list' => $list,
        ]);
    }

    /** Every licence the list's filters give, as a CSV. */
    public function export(Request $request): StreamedResponse
    {
        return $this->history->csv($this->lists->admin($this->history->spec('admin.licences.index'), $request), 'licences-'.now()->format('Y-m-d').'.csv');
    }

    public function show(string $licenceId): View
    {
        return view('admin.licences.show', [
            'licence' => $this->licences->getLicenseDetails($licenceId),
        ]);
    }

    public function create(): View
    {
        return view('admin.licences.create');
    }

    /**
     * Issue a licence and show its secret, once.
     *
     * The plaintext secret exists for exactly the length of this method: the
     * row stores api_secret_hash and the model hides even that, so the array
     * createLicense() returns is the only place it is ever readable. It is
     * flashed to the next request and nowhere else - not persisted, not logged
     * - which is why the detail page can show it and then cannot.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'tier' => ['required', Rule::in(array_column(LicenseTier::cases(), 'value'))],
            // ValidateLicense scopes every partner call to this organization,
            // so a licence without one authenticates and then reaches no
            // tenant. Optional because the column is nullable and a partner is
            // often onboarded before their org row exists; checked against the
            // table because the foreign key would otherwise reject it as a
            // 500 rather than a field error.
            'org_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            // licenses.organization_vat holds fifteen, and MySQL is strict.
            'organization_vat' => ['nullable', 'string', new VatNumber],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            // createLicense() reads $data['org_id'] directly rather than
            // through ??, so the key has to be present even when there is no
            // organization - the missing key is a warning PHPUnit fails on.
            $issued = $this->licences->createLicense([
                'org_id' => $data['org_id'] ?? null,
                'organization_name' => $data['organization_name'],
                'contact_email' => $data['contact_email'],
                'organization_vat' => $data['organization_vat'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'tier' => $data['tier'],
                // Issued from here means usable. The service defaults to
                // pending_activation, and activateLicense() has no screen, so
                // that default would hand the admin a licence they cannot
                // turn on.
                'status' => LicenseStatus::Active->value,
                'expires_at' => $data['expires_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', OperatorError::message($e, 'Issuing the licence', [LicenseException::class]));
        }

        return redirect()
            ->route('admin.licences.show', $issued['license']->id)
            ->with('success', 'Licence issued.')
            ->with('api_secret', $issued['api_secret']);
    }

    /**
     * Issue a new secret for an existing licence.
     *
     * Not routed through attempt(), because this one has something to hand
     * back: the new plaintext, which exists only in the returned array and is
     * never stored. Flashed for the same single request the issue form uses,
     * and shown by the same partial.
     *
     * Destructive in a way the word "regenerate" understates - the partner's
     * current secret stops working the instant this returns, so the button
     * confirms first.
     */
    public function rotate(string $licenceId): RedirectResponse
    {
        try {
            $rotated = $this->licences->regenerateSecret($licenceId);
        } catch (ModelNotFoundException) {
            return redirect()
                ->route('admin.licences.index')
                ->with('error', 'That licence no longer exists.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.licences.show', $licenceId)
                ->with('error', OperatorError::message($e, 'Regenerating the secret', [LicenseException::class]));
        }

        return redirect()
            ->route('admin.licences.show', $licenceId)
            ->with('success', 'Secret regenerated. The partner must update their integration before their next call.')
            ->with('api_secret', $rotated['api_secret']);
    }

    public function suspend(Request $request, string $licenceId): RedirectResponse
    {
        $reason = (string) $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ])['reason'];

        return $this->attempt(
            fn () => $this->licences->suspendLicense($licenceId, $reason),
            'Licence suspended. The partner can no longer call the API.',
            $licenceId,
        );
    }

    /**
     * The customer's own allowances: invoices a month and API calls a day.
     * Empty is the platform's default, unlimited for now.
     */
    public function allowances(Request $request, string $licenceId): RedirectResponse
    {
        $validated = $request->validate([
            'invoices_per_month' => ['nullable', 'integer', 'min:1', 'max:'.(License::UNLIMITED - 1)],
            'calls_per_day' => ['nullable', 'integer', 'min:1', 'max:'.(License::UNLIMITED - 1)],
        ]);
        $defaults = LicenseTier::allowances();

        return $this->attempt(
            fn () => $this->licences->updateLimits($licenceId, [
                'invoices_per_month' => $validated['invoices_per_month'] ?? $defaults['invoices_per_month'],
                'calls_per_day' => $validated['calls_per_day'] ?? $defaults['calls_per_day'],
            ]),
            'Allowances saved.',
            $licenceId,
        );
    }

    public function reactivate(string $licenceId): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->licences->reactivateLicense($licenceId),
            'Licence reactivated.',
            $licenceId,
        );
    }

    public function revoke(Request $request, string $licenceId): RedirectResponse
    {
        $reason = (string) $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ])['reason'];

        return $this->attempt(
            fn () => $this->licences->revokeLicense($licenceId, $reason),
            'Licence revoked.',
            $licenceId,
        );
    }

    /**
     * Run one lifecycle call and report it.
     *
     * A LicenseException reaching a web route is a 500: the renderer in
     * bootstrap/app.php is gated on api/* and v1/*. Catching here is what
     * turns "this licence is already revoked" into a sentence rather than a
     * stack trace.
     *
     * Where the sentence is shown depends on whether the licence exists, which
     * the catch below explains.
     */
    private function attempt(callable $action, string $success, string $licenceId): RedirectResponse
    {
        try {
            $action();
        } catch (\Throwable $e) {
            // When the failure *is* "no such licence", the detail page for that
            // id is a 404: the redirect would consume the flash on a page that
            // never renders, and the admin would see nothing at all. The list
            // is somewhere that exists.
            $target = $e instanceof ModelNotFoundException
                ? redirect()->route('admin.licences.index')
                : redirect()->route('admin.licences.show', $licenceId);

            return $target->with('error', OperatorError::message(
                $e,
                'Changing the licence',
                [LicenseException::class, LicenceStateException::class, ModelNotFoundException::class],
            ));
        }

        return redirect()
            ->route('admin.licences.show', $licenceId)
            ->with('success', $success);
    }
}
