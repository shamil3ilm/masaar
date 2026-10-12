<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
    private const PER_PAGE = 25;

    public function __construct(
        private readonly LicenseManagementService $licences,
    ) {}

    public function index(Request $request): View
    {
        $filters = array_filter([
            'status' => $this->given($request->query('status')),
            'tier' => $this->given($request->query('tier')),
            'search' => $this->given($request->query('q')),
        ], fn (mixed $v): bool => $v !== null);

        return view('admin.licences.index', [
            'licences' => $this->licences->listLicenses($filters, self::PER_PAGE)->withQueryString(),
            'status' => $filters['status'] ?? null,
            'search' => $filters['search'] ?? null,
        ]);
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
            'organization_vat' => ['nullable', 'string', 'max:50'],
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
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.licences.show', $issued['license']->id)
            ->with('success', 'Licence issued.')
            ->with('api_secret', $issued['api_secret']);
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

            return $target->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.licences.show', $licenceId)
            ->with('success', $success);
    }

    /**
     * A query value that was actually given. A malformed `?status[]=x` is an
     * array, and passing that to a where() is a type error, not an empty list.
     */
    private function given(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
