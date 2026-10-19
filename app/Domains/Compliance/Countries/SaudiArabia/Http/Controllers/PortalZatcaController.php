<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\LateReporting;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\ReportingAttempts;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\CertificateRenewal;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
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
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    use ResolvesPortalOrganization;

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
            'defaultDevice' => $this->onboarding->deviceName($organization),
        ]);
    }

    /**
     * Step 1.
     */
    public function ccsid(Request $request): RedirectResponse
    {
        $organization = $this->cleared($request);

        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
            'common_name' => ['required', 'string', 'max:64'],
            'organization_unit' => ['nullable', 'string', 'max:64'],
            'industry' => ['nullable', 'string', 'max:64'],
            'renewal_override_reason' => ['nullable', 'string', 'max:500'],
        ], ['otp.regex' => __('The OTP is the 6 digits the Fatoora portal showed you.')]);

        return $this->step(function () use ($organization, $data): string {
            $this->onboarding->requestCcsid($organization, (string) $data['otp'], [
                'common_name' => $data['common_name'],
                'serial_number' => $this->onboarding->deviceSerial($organization),
                'organization_unit' => $data['organization_unit'] ?: null,
                'industry' => $data['industry'] ?: null,
            ], $data['renewal_override_reason'] ?? null);

            return __('ZATCA issued a compliance certificate. Next, run the compliance checks.');
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
                ? __('All six compliance documents passed. Next, get the production certificate.')
                : __('Some compliance documents were refused. The details are below.'))
            ->with('checks', $result['results']);
    }

    /**
     * Step 3.
     */
    public function production(Request $request): RedirectResponse
    {
        $organization = $this->cleared($request);
        $override = $request->validate(['renewal_override_reason' => ['nullable', 'string', 'max:500']])['renewal_override_reason'] ?? null;

        return $this->step(function () use ($organization, $override): string {
            $this->onboarding->requestPcsid($organization, $override);

            return __('Connected. Invoices you issue are now signed and filed with ZATCA.');
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

    /**
     * Every certificate each of the organization's devices has held: its
     * status, validity, issuer and serial, and how many documents still wait
     * to be filed under it - the ones a renewal or a revocation of it puts at
     * risk - and the way to renew. Its administrators only.
     */
    public function certificates(Request $request, CertificateVersions $versions, CertificateRenewal $renewal): View
    {
        $organization = $this->organization($request);

        return view('countries.saudi-arabia.certificates', [
            'devices' => $versions->forOrganization($organization),
            'renewable' => $renewal->renewable($organization),
        ]);
    }

    /**
     * Simplified invoices not reported within twenty-four hours, and what
     * the organization has done about them (ZATCA Detailed Guidelines for
     * E-Invoicing, p48). Its administrators only.
     */
    public function late(Request $request, LateReporting $late): View
    {
        return view('countries.saudi-arabia.late-reporting', [
            'documents' => $late->for($this->lateOrganization($request)),
        ]);
    }

    /**
     * Record that ZATCA was notified through its website form: of the
     * delay, or again once the invoices are reported. Audited.
     */
    public function lateNotified(Request $request, LateReporting $late): RedirectResponse
    {
        $organizationId = $this->lateOrganization($request);
        $notice = (string) $request->validate(['notice' => ['required', Rule::in(['delay', 'resumed'])]])['notice'];

        $count = $late->recordNotified($organizationId, $notice, (string) $request->user()->id);

        return redirect()->route('portal.zatca.late')->with('success', trans_choice(
            $notice === 'resumed'
                ? 'Recorded that ZATCA was notified again, for one invoice.|Recorded that ZATCA was notified again, for :count invoices.'
                : 'Recorded that ZATCA was notified of the delay, for one invoice.|Recorded that ZATCA was notified of the delay, for :count invoices.',
            $count,
            ['count' => $count],
        ));
    }

    /**
     * Every attempt to reach ZATCA for the late invoices, as CSV: the
     * evidence the Detailed Guidelines ask a taxpayer to keep (p46, p49).
     */
    public function lateEvidence(Request $request, LateReporting $late, ReportingAttempts $attempts): StreamedResponse
    {
        $documents = $late->for($this->lateOrganization($request));

        return response()->streamDownload(function () use ($documents, $attempts): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['invoice_number', 'issued_at_utc', 'attempted_at_utc', 'outcome', 'detail']);

            foreach ($documents as $document) {
                foreach ($attempts->forInvoice((string) $document->org_id, (string) $document->invoice_id) as $attempt) {
                    fputcsv($out, [$document->invoice_number, $document->issued_at, $attempt->attempted_at, $attempt->outcome, $attempt->detail]);
                }
            }

            fclose($out);
        }, 'late-reporting-evidence.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function lateOrganization(Request $request): string
    {
        return $this->portalOrganizationId($request, __('Only an administrator of this organization can see and record late reporting.'));
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
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can connect it to ZATCA.'));
    }
}
