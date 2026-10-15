<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Http\Controllers;

use App\Domains\Compliance\Fatoora\Exceptions\CertificateException;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\FilingClearance;
use App\Domains\Compliance\Fatoora\Services\OrganizationOnboarding;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Connecting the organization to ZATCA, from the portal.
 *
 * The three steps were reachable only over the API, so a business with no
 * developer could not onboard at all. Its administrators only; each step is
 * refused while the company awaits review or has no plan, before the OTP is
 * spent on a certificate it could not use.
 */
class PortalZatcaController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly OrganizationOnboarding $onboarding,
        private readonly FilingClearance $clearance,
    ) {}

    public function show(Request $request): View
    {
        $organization = $this->organization($request);

        return view('portal.zatca', [
            'organization' => $organization,
            'status' => $this->onboarding->status($organization),
            'blocked' => $this->refusal($organization),
            'defaultDevice' => 'EGS1-'.Str::upper(Str::substr(Str::slug((string) $organization->name, ''), 0, 12)),
        ]);
    }

    /**
     * Step 1. The serial number ZATCA expects is the solution, its version
     * and this device: 1-<solution>|2-<version>|3-<device id>.
     */
    public function ccsid(Request $request): RedirectResponse
    {
        $organization = $this->cleared($request);

        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
            'common_name' => ['required', 'string', 'max:64'],
            'organization_unit' => ['nullable', 'string', 'max:64'],
            'industry' => ['nullable', 'string', 'max:64'],
        ], ['otp.regex' => 'The OTP is the 6 digits the Fatoora portal showed you.']);

        return $this->step(function () use ($organization, $data): string {
            $this->onboarding->requestCcsid($organization, (string) $data['otp'], [
                'common_name' => $data['common_name'],
                'serial_number' => '1-'.Str::slug((string) config('app.name', 'Masaar')).'|2-1.0|3-'.Str::uuid(),
                'organization_unit' => $data['organization_unit'] ?: null,
                'industry' => $data['industry'] ?: null,
            ]);

            return 'ZATCA issued a compliance certificate. Next, run the compliance checks.';
        });
    }

    /**
     * Step 2.
     */
    public function check(Request $request): RedirectResponse
    {
        $organization = $this->cleared($request);

        try {
            $result = $this->onboarding->runComplianceCheck($organization);
        } catch (DomainException|CertificateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()
            ->with($result['passed'] ? 'success' : 'error', $result['passed']
                ? 'All six compliance documents passed. Next, get the production certificate.'
                : 'Some compliance documents were refused. The details are below.')
            ->with('checks', $result['results']);
    }

    /**
     * Step 3.
     */
    public function production(Request $request): RedirectResponse
    {
        $organization = $this->cleared($request);

        return $this->step(function () use ($organization): string {
            $this->onboarding->requestPcsid($organization);

            return 'Connected. Invoices you issue are now signed and filed with ZATCA.';
        });
    }

    /**
     * @param  callable(): string  $step
     */
    private function step(callable $step): RedirectResponse
    {
        try {
            return back()->with('success', $step());
        } catch (DomainException|CertificateException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    private function cleared(Request $request): Organization
    {
        $organization = $this->organization($request);
        $refusal = $this->refusal($organization);

        abort_if($refusal !== null, 403, (string) $refusal);

        return $organization;
    }

    private function refusal(Organization $organization): ?string
    {
        try {
            $this->clearance->assertCleared($organization);

            return null;
        } catch (FatooraException $e) {
            return $e->getMessage();
        }
    }

    private function organization(Request $request): Organization
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        abort_unless(is_string($organizationId), 404);
        abort_unless($request->user()?->roleIn($organizationId) === 'admin', 403, 'Only an administrator of this organization can connect it to ZATCA.');

        return $this->tenant->getOrganization() ?? abort(404);
    }
}
