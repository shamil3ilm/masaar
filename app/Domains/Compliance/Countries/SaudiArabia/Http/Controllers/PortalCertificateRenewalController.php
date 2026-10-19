<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\CertificateRenewal;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\RenewalGuard;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\CertificateVersions;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\Tills;
use App\Http\Controllers\Controller;
use App\Support\Errors\ComplianceException;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Renewing a ZATCA certificate from the portal - head office's, or any
 * till's - reached from the Certificates panel.
 *
 * Every step on the ZATCA page is disabled once the organization is
 * onboarded, so renewal was reachable only over the API, and a branch's
 * only there. Here: what the current certificate signed that is not filed
 * yet (RenewalGuard), then a new OTP, which runs all three steps
 * (CertificateRenewal). With documents unfiled the renewal needs a reason,
 * which is audited. A till not yet connected is connected on the same page
 * with its own OTP. Its administrators only, and refused while the company
 * may not file, before the OTP is spent.
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
        private readonly Tills $tills,
    ) {}

    public function show(Request $request, CertificateVersions $versions): View
    {
        $organization = $this->organization($request);
        $headOffice = collect($versions->forOrganization($organization))->firstWhere('device', (string) $organization->id);

        return view('countries.saudi-arabia.certificate-renewal', [
            'till' => null,
            'renewable' => $this->renewal->renewable($organization),
            'pending' => $this->renewal->pending($organization),
            'current' => collect($headOffice['versions'] ?? [])->firstWhere('status', 'active'),
            'waiting' => $this->guard->waiting((string) $organization->id, null),
            'blocked' => $this->refusal($organization),
            'defaultDevice' => $this->onboarding->deviceName($organization),
            'minReason' => RenewalGuard::MIN_REASON,
            'actions' => ['store' => route('portal.zatca.renew.store'), 'finish' => route('portal.zatca.renew.finish')],
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
            'serial_number' => $this->onboarding->deviceSerial($organization),
            'organization_unit' => ($data['organization_unit'] ?? '') ?: null,
            'industry' => ($data['industry'] ?? '') ?: null,
        ], $data['renewal_override_reason'] ?? null), route('portal.zatca.renew'));
    }

    /**
     * The checks and the production certificate, for a renewal whose OTP
     * is already spent.
     */
    public function finish(Request $request): RedirectResponse
    {
        $organization = $this->cleared($request);
        $override = $request->validate(['renewal_override_reason' => ['nullable', 'string', 'max:500']])['renewal_override_reason'] ?? null;

        return $this->step(fn (): array => $this->renewal->finish($organization, $override), route('portal.zatca.renew'));
    }

    /**
     * One till's certificate: connecting it, or renewing it. Head office's
     * own is the organization's renewal.
     */
    public function showTill(Request $request, CertificateVersions $versions, string $unitId): View|RedirectResponse
    {
        $organization = $this->organization($request);
        $unit = $this->till($organization, $unitId);

        if ($unit->isHeadOffice()) {
            return redirect()->route('portal.zatca.renew');
        }

        $device = collect($versions->forOrganization($organization))->firstWhere('device', (string) $unit->id);

        return view('countries.saudi-arabia.certificate-renewal', [
            'till' => $this->tills->label($unit),
            'renewable' => $this->renewal->tillRenewable($unit),
            'pending' => $this->renewal->tillPending($unit),
            'current' => collect($device['versions'] ?? [])->firstWhere('status', 'active'),
            'waiting' => $this->guard->waiting((string) $organization->id, (string) $unit->id),
            'blocked' => $this->refusal($organization) ?? ($unit->issues() ? null : __('Till :till no longer issues invoices (:status). Issue from another till.', ['till' => $unit->label(), 'status' => $unit->statusLabel()])),
            'defaultDevice' => null,
            'minReason' => RenewalGuard::MIN_REASON,
            'actions' => [
                'store' => route('portal.zatca.tills.certificate.renew', $unit->id),
                'finish' => route('portal.zatca.tills.certificate.finish', $unit->id),
            ],
        ]);
    }

    /**
     * The till's own OTP, and all three steps.
     */
    public function renewTill(Request $request, string $unitId): RedirectResponse
    {
        $organization = $this->cleared($request);
        $unit = $this->till($organization, $unitId);

        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
            'renewal_override_reason' => ['nullable', 'string', 'max:500'],
        ], ['otp.regex' => __('The OTP is the 6 digits the Fatoora portal showed you.')]);

        $renewing = $this->renewal->tillRenewable($unit);

        return $this->step(
            fn (): array => $this->renewal->renewTill($organization, $unit, (string) $data['otp'], $data['renewal_override_reason'] ?? null),
            route('portal.zatca.tills.certificate', $unit->id),
            $renewing ? null : __(':till is connected to ZATCA and signs with a certificate of its own.', ['till' => $this->tills->label($unit)]),
        );
    }

    public function finishTill(Request $request, string $unitId): RedirectResponse
    {
        $organization = $this->cleared($request);
        $unit = $this->till($organization, $unitId);
        $override = $request->validate(['renewal_override_reason' => ['nullable', 'string', 'max:500']])['renewal_override_reason'] ?? null;

        $renewing = $this->renewal->tillRenewable($unit);

        return $this->step(
            fn (): array => $this->renewal->finishTill($organization, $unit, $override),
            route('portal.zatca.tills.certificate', $unit->id),
            $renewing ? null : __(':till is connected to ZATCA and signs with a certificate of its own.', ['till' => $this->tills->label($unit)]),
        );
    }

    /**
     * @param  callable(): array{passed: bool, results: array<mixed>}  $step
     * @param  string  $back  the page the step was taken on
     * @param  string|null  $done  what to say once it is done; a renewal's words by default
     */
    private function step(callable $step, string $back, ?string $done = null): RedirectResponse
    {
        try {
            $result = $step();
        } catch (DomainException|CertificateException $e) {
            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }

        if (! $result['passed']) {
            return redirect()->to($back)
                ->with('error', __('Some compliance documents were refused. Your current certificate still signs. Finish the renewal once the details are corrected.'))
                ->with('checks', $result['results']);
        }

        return redirect()->route('portal.zatca.certificates')
            ->with('success', $done ?? __('Renewed. New invoices are signed with the new certificate; the old one still files what it signed.'));
    }

    private function till(Organization $organization, string $unitId): EgsUnit
    {
        return $this->tills->find((string) $organization->id, $unitId) ?? abort(404);
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
