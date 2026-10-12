<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Services\LicenseManagementService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     */
    private function attempt(callable $action, string $success, string $licenceId): RedirectResponse
    {
        try {
            $action();
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.licences.show', $licenceId)
                ->with('error', $e->getMessage());
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
