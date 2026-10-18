<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Exceptions\LicenceStateException;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Domains\Organization\Rules\VatNumber;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Http\Controllers\Controller;
use App\Support\OperatorError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    use HoldsForApproval;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly LicenseManagementService $licences,
        private readonly Approvals $approvals,
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
            // licenses.organization_vat holds fifteen, and MySQL is strict.
            'organization_vat' => ['nullable', 'string', new VatNumber],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            return $this->holdOrRun($this->approvals, $request, 'licences.issue', [
                'org_id' => $data['org_id'] ?? null,
                'organization_name' => $data['organization_name'],
                'contact_email' => $data['contact_email'],
                'organization_vat' => $data['organization_vat'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'tier' => $data['tier'],
                'expires_at' => $data['expires_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], fn (ActionResult $issued): RedirectResponse => redirect()
                ->route('admin.licences.show', $issued->data['licence_id'])
                ->with('success', $issued->message)
                ->with('api_secret', $issued->data['api_secret']));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', OperatorError::message($e, 'Issuing the licence', [LicenseException::class]));
        }
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
            fn () => $this->holdOrRun($this->approvals, $request, 'licences.allowances', [
                'licence_id' => $licenceId,
                'invoices_per_month' => (int) ($validated['invoices_per_month'] ?? $defaults['invoices_per_month']),
                'calls_per_day' => (int) ($validated['calls_per_day'] ?? $defaults['calls_per_day']),
            ], fn (ActionResult $done): RedirectResponse => redirect()->route('admin.licences.show', $licenceId)->with('success', $done->message)),
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
            fn () => $this->holdOrRun($this->approvals, $request, 'licences.revoke', ['licence_id' => $licenceId, 'reason' => $reason],
                fn (ActionResult $done): RedirectResponse => redirect()->route('admin.licences.show', $licenceId)->with('success', $done->message), $reason),
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
            // An action held for approval answers with its request instead.
            $held = $action();

            if ($held instanceof RedirectResponse) {
                return $held;
            }
        } catch (ValidationException $e) {
            throw $e;
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

    /**
     * A query value that was actually given. A malformed `?status[]=x` is an
     * array, and passing that to a where() is a type error, not an empty list.
     */
    private function given(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
