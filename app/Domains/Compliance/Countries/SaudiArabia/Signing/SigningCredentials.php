<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Services\BranchService;
use App\Support\Errors\ComplianceException;
use Illuminate\Support\Facades\Log;

/**
 * The key and certificate a document is signed with, and the device it is
 * signed as. Issuing (IssuingStep) and submitting (Submitter) both ask.
 */
class SigningCredentials
{
    /**
     * Every dependency is required, none optional.
     *
     * The container skips optional constructor parameters, so an optional
     * BranchService resolves to null and the branch credential lookup below
     * becomes unreachable — signing a branch's invoice with the organization's
     * certificate, which ZATCA reads as a different device. A missing binding
     * should fail at boot instead.
     */
    public function __construct(
        private readonly BranchService $branchService,
        private readonly CredentialStore $credentials,
    ) {}

    /**
     * The EGS unit an invoice came from, or null for head office.
     *
     * invoices.branch_id is nullable rather than absent, so isset() asks
     * whether this document came from a particular device.
     */
    public function branchOf(Invoice $invoice): ?Branch
    {
        return isset($invoice->branch_id) ? $invoice->branch : null;
    }

    /**
     * The certificate this invoice is signed with.
     *
     * Credentials live at one of two levels. BranchOnboardingController
     * stores them under a branch; OnboardingController stores them under the
     * organization, with a null branch id.
     *
     * A branch is its own EGS unit holding its own certificate, so an invoice
     * that names a branch signs with that branch's. An organization that has
     * not divided itself into EGS units has a single certificate and no branch
     * to hold it, and its invoices sign with that.
     *
     * @param  string  $organizationId  The organization ID
     * @param  Branch|null  $branch  The branch (optional)
     * @param  bool  $required  If true, throws exception when credentials are missing
     * @return array{privateKey: ?string, certificate: ?string, device: string}
     *
     * @throws FatooraException If required is true and credentials are missing/invalid
     */
    public function for(string $organizationId, ?Branch $branch = null, bool $required = false): array
    {
        // A branch is an EGS unit with its own certificate, so its credentials
        // take precedence over the organization's.
        if ($branch) {
            $branchCredentials = $this->branchService->getCredentials($branch, 'pcsid');

            if ($branchCredentials) {
                $privateKey = $branchCredentials['privateKey'] ?? null;
                $certificate = CertificatePem::from($branchCredentials['pcsid'] ?? null);

                if (! empty($privateKey) && ! empty($certificate)) {
                    Log::debug('Using branch-level credentials', [
                        'org_id' => $organizationId,
                        'branch_id' => $branch->id,
                    ]);

                    return [
                        'privateKey' => $privateKey,
                        'certificate' => $certificate,
                        // The branch is the device: its own counter and chain.
                        'device' => (string) $branch->id,
                    ];
                }
            }
        }

        // The organization's own certificate: used when the invoice names no
        // branch, or the branch it names holds no credentials of its own.
        $path = "zatca/{$organizationId}/pcsid.json";
        $organizationCredentials = $this->credentials->get($organizationId, null, CredentialStore::PCSID);

        if ($organizationCredentials === null) {
            if ($required) {
                // Not the path. FatooraException context is returned to the
                // API caller as well as written to the log, so this disclosed
                // where the taxpayer's signing key is kept on disk to a
                // partner ERP. Which organization has no credential is the
                // actionable part; where it would have been is not.
                $errorContext = [
                    'org_id' => $organizationId,
                ];

                if ($branch) {
                    $errorContext['branch_id'] = $branch->id;
                    $errorContext['branch_name'] = $branch->name;
                }

                throw FatooraException::missingCredentials(
                    'PCSID credentials not found. '.
                    ($branch ? 'Branch' : 'Organization').' must complete ZATCA onboarding '.
                    'to obtain Production CSID (PCSID) before submitting invoices.',
                    $errorContext
                );
            }

            return ['privateKey' => null, 'certificate' => null, 'device' => $organizationId];
        }

        try {
            $data = $organizationCredentials;

            $privateKey = $data['privateKey'] ?? null;
            $certificate = CertificatePem::from($data['pcsid'] ?? null);

            // Validate credentials are not empty when required
            if ($required && (empty($privateKey) || empty($certificate))) {
                throw FatooraException::invalidCredentials(
                    'PCSID credentials are incomplete or corrupted. '.
                    'Please re-run Step 3 of ZATCA onboarding to obtain valid PCSID.',
                    [
                        'org_id' => $organizationId,
                        'has_private_key' => ! empty($privateKey),
                        'has_certificate' => ! empty($certificate),
                    ]
                );
            }

            Log::debug('Using organization-level credentials', [
                'org_id' => $organizationId,
            ]);

            return [
                'privateKey' => $privateKey,
                'certificate' => $certificate,
                'device' => $organizationId,
            ];
        } catch (ComplianceException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($required) {
                throw FatooraException::invalidCredentials(
                    'Failed to decrypt PCSID credentials. The credentials may be corrupted '.
                    'or the application encryption key has changed.',
                    [
                        'org_id' => $organizationId,
                        'error' => $e->getMessage(),
                    ]
                );
            }

            return ['privateKey' => null, 'certificate' => null, 'device' => $organizationId];
        }
    }
}
