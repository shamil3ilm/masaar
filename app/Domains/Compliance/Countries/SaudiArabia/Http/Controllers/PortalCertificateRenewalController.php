<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\CertificateRenewal;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\RenewalGuard;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\CertificateVersions;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Support\Errors\ComplianceException;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Renewing the organization's ZATCA certificate from the portal, reached
 * from the Certificates panel.
 *
 * Every step on the ZATCA page is disabled once the organization is
 * onboarded, so renewal was reachable only over the API. Here: what the
 * current certificate signed that is not filed yet (RenewalGuard), then a
 * new OTP, which runs all three steps (CertificateRenewal). With documents
 * unfiled the renewal needs a reason, which is audited. Its administrators
 * only, and refused while the company may not file, before the OTP is spent.
 */
class PortalCertificateRenewalController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly CertificateRenewal $renewal,
        private readonly OrganizationOnboarding $onboarding,
        private readonly RenewalGuard $guard,
        private readonly FilingClearance $clearance,
    ) {}

    public function show(Request $request, CertificateVersions $versions): View
    {
        $organization = $this->organization($request);
        $headOffice = collect($versions->forOrganization($organization))->firstWhere('device', (string) $organization->id);

        return view('countries.saudi-arabia.certificate-renewal', [
            'renewable' => $this->renewal->renewable($organization),
            'pending' => $this->renewal->pending($organization),
            'current' => collect($headOffice['versions'] ?? [])->firstWhere('status', 'active'),
            'waiting' => $this->guard->waiting((string) $organization->id, null),
            'blocked' => $this->refusal($organization),
            'defaultDevice' => $this->onboarding->deviceName($organization),
            'minReason' => RenewalGuard::MIN_REASON,
        ]);
    }

    /**
     * The OTP, and all three steps.
     */
    public function renew(Request $request): RedirectResponse
    {
        $organization = $this->cleared($request);

        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
            'common_name' => ['required', 'string', 'max:64'],
            'organization_unit' => ['nullable', 'string', 'max:64'],
            'industry' => ['nullable', 'string', 'max:64'],
            'renewal_override_reason' => ['nullable', 'string', 'max:500'],
        ], ['otp.regex' => __('The OTP is the 6 digits the Fatoora portal showed you.')]);

        return $this->step(fn (): array => $this->renewal->renew($organization, (string) $data['otp'], [
            'common_name' => $data['common_name'],
            'serial_number' => $this->onboarding->deviceSerial(),
            'organization_unit' => ($data['organization_unit'] ?? '') ?: null,
            'industry' => ($data['industry'] ?? '') ?: null,
        ], $data['renewal_override_reason'] ?? null));
    }

    /**
     * The checks and the production certificate, for a renewal whose OTP
     * is already spent.
     */
    public function finish(Request $request): RedirectResponse
    {
        $organization = $this->cleared($request);
        $override = $request->validate(['renewal_override_reason' => ['nullable', 'string', 'max:500']])['renewal_override_reason'] ?? null;

        return $this->step(fn (): array => $this->renewal->finish($organization, $override));
    }

    /**
     * @param  callable(): array{passed: bool, results: array<mixed>}  $step
     */
    private function step(callable $step): RedirectResponse
    {
        try {
            $result = $step();
        } catch (DomainException|CertificateException $e) {
            return redirect()->route('portal.zatca.renew')->withInput()->with('error', $e->getMessage());
        }

        if (! $result['passed']) {
            return redirect()->route('portal.zatca.renew')
                ->with('error', __('Some compliance documents were refused. Your current certificate still signs. Finish the renewal once the details are corrected.'))
                ->with('checks', $result['results']);
        }

        return redirect()->route('portal.zatca.certificates')
            ->with('success', __('Renewed. New invoices are signed with the new certificate; the old one still files what it signed.'));
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
        } catch (ComplianceException $e) {
            return $e->getMessage();
        }
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can renew its ZATCA certificate.'));
    }
}
